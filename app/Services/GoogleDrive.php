<?php

namespace App\Services;

use App\Models\Ajuste;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Subida de copias a Google Drive (API v3, OAuth 2.0) sin librerías externas.
 * Ámbito «drive.file»: la app solo ve los archivos que ella misma crea.
 */
class GoogleDrive
{
    private const AUTH = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN = 'https://oauth2.googleapis.com/token';

    private const API = 'https://www.googleapis.com/drive/v3';

    private const UPLOAD = 'https://www.googleapis.com/upload/drive/v3/files';

    private const SCOPE = 'https://www.googleapis.com/auth/drive.file';

    public function configurado(): bool
    {
        return (bool) config('services.google_drive.client_id') && (bool) config('services.google_drive.client_secret');
    }

    public function conectado(): bool
    {
        return $this->configurado() && (bool) Ajuste::get('drive.refresh_token');
    }

    public function urlAutorizacion(string $state): string
    {
        return self::AUTH.'?'.http_build_query([
            'client_id' => config('services.google_drive.client_id'),
            'redirect_uri' => route('admin.copias.drive.callback'),
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);
    }

    public function canjearCodigo(string $code): void
    {
        $r = Http::asForm()->timeout(30)->post(self::TOKEN, [
            'code' => $code,
            'client_id' => config('services.google_drive.client_id'),
            'client_secret' => config('services.google_drive.client_secret'),
            'redirect_uri' => route('admin.copias.drive.callback'),
            'grant_type' => 'authorization_code',
        ]);
        if (! $r->ok() || ! $r->json('refresh_token')) {
            throw new RuntimeException('Google no devolvió el permiso. Vuelve a intentarlo.');
        }
        Ajuste::put('drive.refresh_token', $r->json('refresh_token'));
        Ajuste::put('drive.folder_id', null);
    }

    public function desconectar(): void
    {
        Ajuste::put('drive.refresh_token', null);
        Ajuste::put('drive.folder_id', null);
    }

    private function token(): string
    {
        $rt = Ajuste::get('drive.refresh_token');
        if (! $rt) {
            throw new RuntimeException('Google Drive no está conectado.');
        }
        $r = Http::asForm()->timeout(30)->post(self::TOKEN, [
            'client_id' => config('services.google_drive.client_id'),
            'client_secret' => config('services.google_drive.client_secret'),
            'refresh_token' => $rt,
            'grant_type' => 'refresh_token',
        ]);
        if (! $r->ok()) {
            throw new RuntimeException('Google Drive rechazó el acceso ('.$r->status().'). Reconecta la cuenta en Copias de seguridad.');
        }

        return (string) $r->json('access_token');
    }

    private function carpeta(string $t): string
    {
        $id = Ajuste::get('drive.folder_id');
        if ($id) {
            $r = Http::withToken($t)->get(self::API.'/files/'.$id, ['fields' => 'id,trashed']);
            if ($r->ok() && ! $r->json('trashed')) {
                return $id;
            }
        }
        $r = Http::withToken($t)->post(self::API.'/files', ['name' => 'AG Cloud · Copias de seguridad', 'mimeType' => 'application/vnd.google-apps.folder']);
        if (! $r->ok()) {
            throw new RuntimeException('No se pudo crear la carpeta en Google Drive.');
        }
        Ajuste::put('drive.folder_id', $r->json('id'));

        return (string) $r->json('id');
    }

    /** Subida reanudable por bloques de 8 MB. Devuelve el id del archivo. */
    public function subir(string $ruta): string
    {
        $t = $this->token();
        $folder = $this->carpeta($t);
        $size = filesize($ruta);
        $ini = Http::withToken($t)->withHeaders(['X-Upload-Content-Type' => 'application/zip', 'X-Upload-Content-Length' => (string) $size])
            ->post(self::UPLOAD.'?uploadType=resumable', ['name' => basename($ruta), 'parents' => [$folder]]);
        $url = $ini->header('Location');
        if (! $ini->ok() || ! $url) {
            throw new RuntimeException('Google Drive no aceptó la subida ('.$ini->status().').');
        }
        $fh = fopen($ruta, 'rb');
        $off = 0;
        $chunk = 8 * 1024 * 1024;
        $id = null;
        while ($off < $size) {
            $data = fread($fh, $chunk);
            $end = $off + strlen($data) - 1;
            $r = Http::withToken($t)->timeout(300)->withHeaders(['Content-Range' => "bytes $off-$end/$size"])->withBody($data, 'application/zip')->put($url);
            if ($r->status() === 308) {
                $off = $end + 1;

                continue;
            }
            if (! $r->successful()) {
                fclose($fh);
                throw new RuntimeException('Fallo al subir a Google Drive ('.$r->status().').');
            }
            $id = $r->json('id');
            $off = $size;
        }
        fclose($fh);

        return (string) $id;
    }

    /** Borra de la carpeta las copias con más de $dias días. Devuelve cuántas. */
    public function limpiar(int $dias): int
    {
        $t = $this->token();
        $folder = $this->carpeta($t);
        $lim = now()->subDays($dias)->toRfc3339String();
        $r = Http::withToken($t)->get(self::API.'/files', ['q' => "'$folder' in parents and trashed = false and createdTime < '$lim'", 'fields' => 'files(id,name)', 'pageSize' => 100]);
        $n = 0;
        foreach ((array) $r->json('files') as $f) {
            if (str_starts_with((string) $f['name'], 'agcloud-') && Http::withToken($t)->delete(self::API.'/files/'.$f['id'])->successful()) {
                $n++;
            }
        }

        return $n;
    }
}
