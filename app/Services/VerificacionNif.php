<?php

namespace App\Services;

use App\Models\Ajuste;
use DOMDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

/**
 * Verificación de NIF en el censo de la AEAT: servicio web «VNifV2» (SOAP con certificado electrónico).
 *
 * - Se envía el NIF y el nombre o razón social; la AEAT responde IDENTIFICADO, NO IDENTIFICADO,
 *   IDENTIFICADO-BAJA o IDENTIFICADO-REVOCADO (y el nombre que le consta).
 * - Exige autenticarse con un certificado electrónico: el del despacho se guarda cifrado en la base
 *   de datos (ajuste «aeat.certificado») y solo se descifra en memoria y en un temporal que se borra.
 * - Solo consulta: no presenta nada ni envía ficheros a la AEAT.
 */
class VerificacionNif
{
    public const URL = 'https://www1.agenciatributaria.gob.es/wlpl/BURT-JDIT/ws/VNifV2SOAP';

    private const NS_ENT = 'http://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/burt/jdit/ws/VNifV2Ent.xsd';

    private const CLAVE = 'aeat.certificado';

    /** Datos del certificado guardado (sin el fichero ni la contraseña), o null */
    public function certificado(): ?array
    {
        $d = $this->leer();

        return $d ? array_intersect_key($d, array_flip(['titular', 'nif', 'caduca', 'emisor', 'activo', 'subido'])) : null;
    }

    public function activo(): bool
    {
        $d = $this->leer();

        return (bool) ($d['activo'] ?? false) && ($d['caduca'] ?? '') >= now()->toDateString();
    }

    public function activar(bool $on): void
    {
        $d = $this->leer();
        if ($d) {
            $d['activo'] = $on;
            Ajuste::put(self::CLAVE, json_encode($d));
        }
    }

    /** Guarda el certificado (.p12/.pfx) tras comprobar la contraseña. Devuelve sus datos. */
    public function guardar(UploadedFile $f, string $pass): array
    {
        $raw = (string) file_get_contents($f->getRealPath());
        $c = [];
        if (! openssl_pkcs12_read($raw, $c, $pass)) {
            throw new InvalidArgumentException('No se puede abrir el certificado: la contraseña no es correcta o el fichero no es un .p12/.pfx válido.');
        }
        $x = openssl_x509_parse($c['cert']) ?: [];
        $sub = $x['subject'] ?? [];
        $caduca = isset($x['validTo_time_t']) ? date('Y-m-d', $x['validTo_time_t']) : '';
        if ($caduca !== '' && $caduca < now()->toDateString()) {
            throw new InvalidArgumentException('El certificado caducó el '.date('d/m/Y', $x['validTo_time_t']).'.');
        }
        $nif = (string) ($sub['serialNumber'] ?? '');
        $nif = preg_replace('/^(IDCES|VATES)-?/', '', $nif);
        $d = ['p12' => base64_encode($raw), 'pass' => $pass, 'titular' => (string) ($sub['CN'] ?? $sub['O'] ?? ''), 'nif' => $nif, 'caduca' => $caduca,
            'emisor' => (string) (($x['issuer']['O'] ?? '') ?: ($x['issuer']['CN'] ?? '')), 'activo' => true, 'subido' => now()->toDateTimeString()];
        Ajuste::put(self::CLAVE, json_encode($d));

        return $this->certificado();
    }

    public function quitar(): void
    {
        Ajuste::where('clave', self::CLAVE)->delete();
    }

    /**
     * Consulta un NIF.
     *
     * @return array{estado: string, resultado: string, nombre: string, msg: string}
     *                estado: ok | no | baja | revocado | error
     */
    public function comprobar(string $nif, string $nombre): array
    {
        $d = $this->leer();
        if (! $d) {
            return ['estado' => 'error', 'resultado' => '', 'nombre' => '', 'msg' => 'No hay certificado configurado para consultar la AEAT.'];
        }
        $c = [];
        if (! openssl_pkcs12_read(base64_decode($d['p12']), $c, (string) $d['pass'])) {
            return ['estado' => 'error', 'resultado' => '', 'nombre' => '', 'msg' => 'No se puede abrir el certificado guardado.'];
        }
        // Temporales: el certificado y la clave privada protegida con una contraseña de un solo uso
        $tmpPass = bin2hex(random_bytes(16));
        $keyPem = '';
        openssl_pkey_export($c['pkey'], $keyPem, $tmpPass);
        $certPem = $c['cert'].implode('', (array) ($c['extracerts'] ?? []));
        $certFile = tempnam(sys_get_temp_dir(), 'agc');
        $keyFile = tempnam(sys_get_temp_dir(), 'agk');
        try {
            chmod($certFile, 0600);
            chmod($keyFile, 0600);
            file_put_contents($certFile, $certPem);
            file_put_contents($keyFile, $keyPem);
            $resp = Http::withOptions(['cert' => $certFile, 'ssl_key' => [$keyFile, $tmpPass]])
                ->withHeaders(['Content-Type' => 'text/xml; charset=UTF-8', 'SOAPAction' => '""'])
                ->timeout(20)->connectTimeout(10)
                ->withBody($this->peticion($nif, $nombre), 'text/xml; charset=UTF-8')
                ->post((string) config('agcloud.aeat_vnif_url', self::URL));

            return $this->interpretar($resp->status(), (string) $resp->body());
        } catch (Throwable $e) {
            report($e);

            return ['estado' => 'error', 'resultado' => '', 'nombre' => '', 'msg' => 'No se ha podido conectar con la AEAT ahora mismo.'];
        } finally {
            @unlink($certFile);
            @unlink($keyFile);
        }
    }

    /** Sobre SOAP de la consulta (esquema VNifV2Ent) */
    public function peticion(string $nif, string $nombre): string
    {
        $x = fn (string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $nombre = mb_strtoupper(trim(preg_replace('/\s+/', ' ', $nombre)), 'UTF-8');

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:vnif="'.self::NS_ENT.'">'
            .'<soapenv:Header/><soapenv:Body><vnif:VNifV2Ent><vnif:Contribuyente>'
            .'<vnif:Nif>'.$x(strtoupper($nif)).'</vnif:Nif><vnif:Nombre>'.$x(mb_substr($nombre, 0, 80)).'</vnif:Nombre>'
            .'</vnif:Contribuyente></vnif:VNifV2Ent></soapenv:Body></soapenv:Envelope>';
    }

    /** Lee la respuesta SOAP (VNifV2Sal) o el SOAP Fault */
    public function interpretar(int $status, string $xml): array
    {
        $err = fn (string $m) => ['estado' => 'error', 'resultado' => '', 'nombre' => '', 'msg' => $m];
        if (trim($xml) === '') {
            return $err('La AEAT no ha respondido (HTTP '.$status.').');
        }
        $dom = new DOMDocument;
        $prev = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($xml, LIBXML_NONET);   // sin red ni sustitución de entidades
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (! $ok) {
            return $err('La AEAT ha devuelto una respuesta que no se puede leer (HTTP '.$status.').');
        }
        $val = function (string $tag) use ($dom): string {
            $n = $dom->getElementsByTagNameNS('*', $tag)->item(0);

            return $n ? trim($n->textContent) : '';
        };
        $fault = $val('faultstring');
        if ($fault !== '') {
            return $err('La AEAT ha rechazado la consulta: '.$fault);
        }
        $res = mb_strtoupper($val('Resultado'));
        $nombre = $val('Nombre');
        if ($res === '') {
            return $err('Respuesta de la AEAT sin resultado (HTTP '.$status.').');
        }
        $estado = match (true) {
            str_contains($res, 'REVOCADO') => 'revocado',
            str_contains($res, 'BAJA') => 'baja',
            str_starts_with($res, 'NO IDENTIFICADO') => 'no',
            str_starts_with($res, 'IDENTIFICADO') => 'ok',
            default => 'error',
        };
        $msg = match ($estado) {
            'ok' => 'NIF identificado en la AEAT'.($nombre !== '' ? ': '.$nombre : '').'.',
            'no' => 'La AEAT no identifica este NIF con ese nombre o razón social.',
            'baja' => 'El NIF figura de baja en el censo de la AEAT.',
            'revocado' => 'El NIF está revocado por la AEAT.',
            default => 'Resultado de la AEAT no reconocido: '.$res.'.',
        };

        return ['estado' => $estado, 'resultado' => $res, 'nombre' => $nombre, 'msg' => $msg];
    }

    private function leer(): ?array
    {
        try {
            $j = Ajuste::get(self::CLAVE);
        } catch (Throwable) {
            return null;
        }

        return $j ? (json_decode($j, true) ?: null) : null;
    }
}
