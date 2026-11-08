<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Almacén de documentos cifrados en reposo.
 *
 * - Los archivos se guardan en storage/app/privado (fuera de public_html).
 * - Se cifran por bloques de 64 KB con XChaCha20-Poly1305 (libsodium,
 *   cifrado autenticado de 256 bits): si alguien modifica el archivo en el
 *   servidor, el descifrado falla en lugar de devolver datos alterados.
 * - La clave es DOCS_KEY (.env), distinta de APP_KEY. SIN ESA CLAVE LOS
 *   DOCUMENTOS NO SE PUEDEN RECUPERAR: guárdala también fuera del servidor.
 */
class AlmacenDocumentos
{
    private const MAGIC = 'AGC1';

    private const CHUNK = 65536;

    public function raiz(): string
    {
        return rtrim((string) config('agcloud.docs_path', storage_path('app/privado')), '/');
    }

    private function clave(): string
    {
        $k = (string) config('app.docs_key');
        if (str_starts_with($k, 'base64:')) {
            $k = base64_decode(substr($k, 7), true) ?: '';
        }
        if (strlen($k) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw new RuntimeException('Falta DOCS_KEY o no es válida. Ejecuta: php artisan agcloud:instalar');
        }

        return $k;
    }

    public static function generarClave(): string
    {
        return 'base64:'.base64_encode(sodium_crypto_secretstream_xchacha20poly1305_keygen());
    }

    /**
     * Cifra y guarda un archivo subido.
     *
     * @return array{ruta: string, tamano: int, sha256: string, mime: string}
     */
    public function guardar(UploadedFile $file, int $clienteId): array
    {
        $rel = 'clientes/'.$clienteId.'/'.now()->format('Y').'/'.Str::uuid().'.agc';
        $dest = $this->raiz().'/'.$rel;
        if (! is_dir(dirname($dest))) {
            mkdir(dirname($dest), 0750, true);
        }

        $in = fopen($file->getRealPath(), 'rb');
        $out = fopen($dest, 'wb');
        if (! $in || ! $out) {
            throw new RuntimeException('No se pudo guardar el archivo.');
        }
        $hash = hash_init('sha256');
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($this->clave());
        fwrite($out, self::MAGIC.$header);

        $tam = 0;
        $chunk = fread($in, self::CHUNK);
        while ($chunk !== false) {
            $next = feof($in) ? false : fread($in, self::CHUNK);
            $last = $next === false || $next === '';
            $tam += strlen($chunk);
            hash_update($hash, $chunk);
            $tag = $last ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
            $c = sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $tag);
            fwrite($out, pack('N', strlen($c)).$c);
            if ($last) {
                break;
            }
            $chunk = $next;
        }
        fclose($in);
        fclose($out);
        @chmod($dest, 0640);

        return [
            'ruta' => $rel,
            'tamano' => $tam,
            'sha256' => hash_final($hash),
            'mime' => $this->mimeSeguro($file),
        ];
    }

    /** Envía el contenido descifrado al flujo de salida, bloque a bloque. */
    public function emitir(string $ruta): void
    {
        $this->descifrar($ruta, function (string $plano) {
            echo $plano;
            flush();
        });
    }

    public function contenido(string $ruta): string
    {
        $buf = '';
        $this->descifrar($ruta, function (string $p) use (&$buf) {
            $buf .= $p;
        });

        return $buf;
    }

    private function descifrar(string $ruta, callable $salida): void
    {
        $path = $this->ruta($ruta);
        $in = fopen($path, 'rb');
        if (! $in) {
            throw new RuntimeException('Archivo no encontrado.');
        }
        $magic = fread($in, 4);
        $header = fread($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
        if ($magic !== self::MAGIC || strlen($header) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
            throw new RuntimeException('Formato de archivo no reconocido.');
        }
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $this->clave());
        $fin = false;
        while (! $fin && ($len = fread($in, 4)) !== false && strlen($len) === 4) {
            $n = unpack('N', $len)[1];
            $c = $n > 0 ? fread($in, $n) : '';
            $res = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $c);
            if ($res === false) {
                fclose($in);
                throw new RuntimeException('El archivo está dañado o ha sido manipulado.');
            }
            [$plano, $tag] = $res;
            $salida($plano);
            $fin = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
        }
        fclose($in);
        if (! $fin) {
            throw new RuntimeException('El archivo está incompleto.');
        }
    }

    public function borrar(string $ruta): void
    {
        $p = $this->ruta($ruta);
        if (is_file($p)) {
            unlink($p);
        }
    }

    public function existe(string $ruta): bool
    {
        return is_file($this->ruta($ruta));
    }

    private function ruta(string $rel): string
    {
        // Evita salir de la carpeta privada
        if (str_contains($rel, '..') || str_starts_with($rel, '/')) {
            throw new RuntimeException('Ruta no válida.');
        }

        return $this->raiz().'/'.$rel;
    }

    /** MIME detectado por contenido (no por la extensión que manda el navegador) */
    private function mimeSeguro(UploadedFile $f): string
    {
        $m = $f->getMimeType() ?: 'application/octet-stream';

        return mb_substr($m, 0, 120);
    }
}
