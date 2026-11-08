<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Documento;
use App\Services\AlmacenDocumentos;
use App\Services\Auditoria;
use App\Support\ClasificadorDocumentos;
use App\Support\SeccionesAlta;
use App\Support\Validacion;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/** Secciones de lista de la ficha (bancos, centros, actividades…), logo y firma. */
class ClienteSeccionController extends Controller
{
    public function guardar(Request $request, Cliente $cliente, string $seccion, AlmacenDocumentos $almacen)
    {
        $spec = SeccionesAlta::spec($seccion, $cliente->tipo);
        abort_unless($spec && array_key_exists($seccion, SeccionesAlta::secciones($cliente->tipo)), 404);

        $lista = $cliente->seccion($seccion);
        $idx = $request->filled('idx') ? (int) $request->input('idx') : null;
        abort_if($idx !== null && ! isset($lista[$idx]), 404);

        $in = (array) $request->input('d', []);
        $d = $idx !== null ? $lista[$idx] : SeccionesAlta::blank($seccion, $cliente, $lista);
        $volver = fn (string $msg) => throw new HttpResponseException(
            redirect()->route('clientes.edit', [$cliente, 'sec' => $seccion, 'item' => $idx ?? 'nuevo'])->withInput()->withErrors(['sub' => $msg])
        );

        foreach ($spec['fields'] as $f) {
            [$k, , $tipo] = $f;
            $opts = $f[3] ?? null;
            if ($tipo === 'file') {
                continue;
            }
            if ($tipo === 'tog') {
                $d[$k] = ! empty($in[$k]);
            } elseif ($tipo === 'password') {
                if (($in[$k] ?? '') !== '') {
                    $d[$k] = mb_substr((string) $in[$k], 0, 200);
                }
            } else {
                $val = trim((string) ($in[$k] ?? ''));
                if ($tipo === 'sel' && is_array($opts) && $val !== '' && ! in_array($val, $opts, true)) {
                    $volver('Valor no válido en «'.rtrim($f[1], ' *').'».');
                }
                if ($tipo === 'date' && $val !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
                    $volver('Fecha no válida en «'.rtrim($f[1], ' *').'».');
                }
                $d[$k] = mb_substr($val, 0, 300);
            }
        }

        // Normalizaciones y autocompletados del prototipo
        if ($seccion === 'bancos') {
            $d['iban'] = strtoupper(preg_replace('/\s+/', '', $d['iban']));
            if ($b = Validacion::banco($d['iban'])) {
                $d['entidad'] = $d['entidad'] ?: $b[0];
                $d['bic'] = $d['bic'] ?: $b[1];
            }
        }
        if ($seccion === 'reps') {
            $d['nif'] = Validacion::normalizaNif($d['nif']);
        }
        if (in_array($seccion, ['centros', 'acts'], true) && ! empty($d['ccc'])) {
            $p = Validacion::provinciaDe($d['ccc']);
            if ($seccion === 'centros' && $p && empty($in['prov'])) {
                $d['prov'] = $p;
            }
        }

        // Archivo (escritura o certificado): se guarda cifrado en el gestor documental
        $file = $request->file('archivo');
        if ($file) {
            $request->validate(['archivo' => ['file', 'max:'.config('agcloud.max_upload_kb')]]);
            if ($seccion === 'certs') {
                $leido = $this->leerCertificado($file, (string) ($d['pass'] ?? ''));
                if ($leido === false) {
                    $volver('No se pudo leer el certificado. Revisa la contraseña o el archivo.');
                }
                if (is_array($leido)) {
                    $d = array_merge($d, array_filter($leido, fn ($x) => $x !== ''));
                    $d['leido'] = true;
                }
            }
        }

        if ($err = SeccionesAlta::check($seccion, $d, $lista, $idx)) {
            $volver($err);
        }

        if ($file) {
            $carpeta = $seccion === 'certs' ? 'ident/certdig' : 'mercantil/escrituras';
            $prop = ClasificadorDocumentos::proponer($file->getClientOriginalName(), $cliente->nombre);
            $doc = $this->guardarDocumento($almacen, $file, $cliente, $carpeta, $seccion === 'certs' ? 'cert' : 'docu', $prop['nombre'], $seccion === 'certs' ? ($d['fCad'] ?: null) : null);
            $d['archivo'] = ['id' => $doc->id, 'name' => $file->getClientOriginalName()];
        }

        // Un solo «principal» por sección
        if (! empty($d['principal'])) {
            foreach ($lista as $i => $r) {
                $lista[$i]['principal'] = false;
            }
        }
        if ($idx !== null) {
            $lista[$idx] = $d;
        } else {
            $lista[] = $d;
        }
        $cliente->setSeccion($seccion, $lista);
        if ($seccion === 'acts' && ! empty($d['principal'])) {
            $cliente->actividad = mb_substr($d['desc'], 0, 250);
        }
        $cliente->save();
        Auditoria::registrar('cliente.seccion', ($idx === null ? 'Añade ' : 'Edita ').$spec['one'].' en la ficha de '.$cliente->nombre, $cliente, $cliente->id);

        return redirect()->route('clientes.edit', [$cliente, 'sec' => $seccion])->with('ok', ucfirst($spec['one']).' guardado.');
    }

    public function borrar(Cliente $cliente, string $seccion, int $idx)
    {
        $spec = SeccionesAlta::spec($seccion, $cliente->tipo);
        abort_unless($spec, 404);
        $lista = $cliente->seccion($seccion);
        abort_unless(isset($lista[$idx]), 404);
        array_splice($lista, $idx, 1);
        $cliente->setSeccion($seccion, $lista);
        $cliente->save();
        Auditoria::registrar('cliente.seccion', 'Elimina '.$spec['one'].' de la ficha de '.$cliente->nombre, $cliente, $cliente->id);

        return redirect()->route('clientes.edit', [$cliente, 'sec' => $seccion])->with('ok', ucfirst($spec['one']).' eliminado.');
    }

    // ----------------------------------------------------------- logo y firma
    public function imagen(Request $request, Cliente $cliente, string $slot, AlmacenDocumentos $almacen)
    {
        $request->validate(['imagen' => ['required', 'file', 'max:4096', 'mimetypes:image/png,image/jpeg,image/webp,image/gif']], [], ['imagen' => 'la imagen']);
        $f = $request->file('imagen');
        $col = $slot.'_documento_id';
        $old = $cliente->$col ? Documento::find($cliente->$col) : null;
        $doc = $this->guardarDocumento($almacen, $f, $cliente, 'otros/varios', 'docu', ClasificadorDocumentos::nombreSeguro($f->getClientOriginalName()), null, 'ficha');
        $cliente->$col = $doc->id;
        $cliente->save();
        if ($old) {
            $almacen->borrar($old->ruta);
            $old->delete();
        }
        Auditoria::registrar('cliente.imagen', ($slot === 'logo' ? 'Logo' : 'Firma').' actualizado de '.$cliente->nombre, $cliente, $cliente->id);

        return redirect()->route('clientes.edit', [$cliente, 'sec' => 'logo'])->with('ok', ($slot === 'logo' ? 'Logo' : 'Firma').' guardado.');
    }

    public function quitarImagen(Cliente $cliente, string $slot, AlmacenDocumentos $almacen)
    {
        $col = $slot.'_documento_id';
        if ($cliente->$col && ($doc = Documento::find($cliente->$col))) {
            $almacen->borrar($doc->ruta);
            $doc->delete();
        }
        $cliente->$col = null;
        $cliente->save();

        return redirect()->route('clientes.edit', [$cliente, 'sec' => 'logo'])->with('ok', 'Imagen quitada.');
    }

    public function verImagen(Cliente $cliente, string $slot, AlmacenDocumentos $almacen)
    {
        $col = $slot.'_documento_id';
        $doc = $cliente->$col ? Documento::where('cliente_id', $cliente->id)->find($cliente->$col) : null;
        abort_unless($doc && $doc->esImagen(), 404);

        return response()->stream(fn () => $almacen->emitir($doc->ruta), 200, [
            'Content-Type' => $doc->mime, 'Cache-Control' => 'private, max-age=600', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    // ----------------------------------------------------------- utilidades
    private function guardarDocumento(AlmacenDocumentos $almacen, UploadedFile $f, Cliente $c, string $carpeta, string $tipo, string $nombre, ?string $caduca, string $ambito = 'gestor'): Documento
    {
        $g = $almacen->guardar($f, $c->id);

        return Documento::create([
            'cliente_id' => $c->id, 'ambito' => $ambito, 'carpeta' => $carpeta, 'tipo' => $tipo,
            'nombre' => ClasificadorDocumentos::nombreSeguro($nombre), 'nombre_original' => ClasificadorDocumentos::nombreSeguro($f->getClientOriginalName()),
            'fecha' => now()->toDateString(), 'caduca' => $caduca, 'mime' => $g['mime'], 'tamano' => $g['tamano'], 'ruta' => $g['ruta'], 'sha256' => $g['sha256'],
            'origen' => 'Despacho', 'compartido' => false, 'por_revisar' => false, 'subido_por' => auth()->id(),
        ]);
    }

    /**
     * Lee un .p12/.pfx con su contraseña (o un .cer/.crt/.pem sin ella).
     * Devuelve los datos extraídos, null si no es un certificado legible sin
     * contraseña, o false si la contraseña no abre el archivo.
     */
    private function leerCertificado(UploadedFile $f, string $pass): array|false|null
    {
        $raw = (string) file_get_contents($f->getRealPath());
        $ext = strtolower($f->getClientOriginalExtension());
        $x509 = null;
        if (in_array($ext, ['p12', 'pfx'], true)) {
            if ($pass === '') {
                return null;
            }
            $certs = [];
            if (! @openssl_pkcs12_read($raw, $certs, $pass)) {
                return false;
            }
            $x509 = $certs['cert'] ?? null;
        } else {
            $pem = str_contains($raw, '-----BEGIN') ? $raw : "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($raw), 64)."-----END CERTIFICATE-----\n";
            $x509 = $pem;
        }
        $p = $x509 ? @openssl_x509_parse($x509) : false;
        if (! $p) {
            return null;
        }
        $sub = $p['subject'] ?? [];
        $iss = $p['issuer'] ?? [];
        $cn = is_array($sub['CN'] ?? null) ? implode(' ', $sub['CN']) : (string) ($sub['CN'] ?? '');
        $nif = (string) ($sub['serialNumber'] ?? '');
        $nif = preg_replace('/^(IDCES-|VATES-)/', '', $nif);
        $org = (string) ($sub['O'] ?? '');
        $emisorTxt = (string) ($iss['O'] ?? $iss['CN'] ?? '');
        $emisor = collect(config('agcloud.emisores'))->first(fn ($e) => $e !== 'Otro' && stripos($emisorTxt, explode('-', $e)[0]) !== false) ?? 'Otro';

        return [
            'titular' => mb_substr(preg_replace('/\s*-\s*\d{8}[A-Z]$|\s*\(R:.*\)$/u', '', $cn), 0, 200),
            'nifT' => mb_substr($nif, 0, 15),
            'empresaCert' => mb_substr($org, 0, 200),
            'emisor' => $emisor,
            'fEmi' => isset($p['validFrom_time_t']) ? date('Y-m-d', $p['validFrom_time_t']) : '',
            'fCad' => isset($p['validTo_time_t']) ? date('Y-m-d', $p['validTo_time_t']) : '',
        ];
    }
}
