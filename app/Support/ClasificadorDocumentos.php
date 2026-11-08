<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Propone carpeta, tipo y nombre con la nomenclatura del despacho
 * (aaaammdd_tipo_descripcion.ext) a partir del nombre del archivo.
 * Portado de docGuess() del prototipo.
 */
class ClasificadorDocumentos
{
    private const PREFIJOS = ['fra' => 'contable/recibidas', 'alb' => 'contable/recibidas', 'rec' => 'contable/recibidas', 'nom' => 'laboral/nominas', 'mod' => 'fiscal/modelos', 'ext' => 'contable/bancos', 'tick' => 'contable/recibidas', 'contr' => 'laboral/contratos', 'acta' => 'mercantil/actas', 'cert' => 'fiscal/certificados', 'poder' => 'mercantil/poderes', 'cv' => 'laboral/altas', 'docu' => 'otros/varios'];

    private const REGLAS = [
        ['/factura|fra[\s_-]|invoice/', 'fra', 'contable/recibidas'],
        ['/albaran/', 'alb', 'contable/recibidas'],
        ['/nomina|payroll/', 'nom', 'laboral/nominas'],
        ['/rlc|rnt|seguros sociales|tc1|tc2/', 'rec', 'laboral/seguros'],
        ['/contrato/', 'contr', 'laboral/contratos'],
        ['/\b(036|037)\b/', 'mod', 'fiscal/censal'],
        ['/modelo|\b(303|111|115|130|131|180|190|200|202|347|349|390|100)\b/', 'mod', 'fiscal/modelos'],
        ['/requerimiento|notificacion|providencia|liquidacion/', 'docu', 'fiscal/requerimientos'],
        ['/extracto|movimientos|n43|norma ?43/', 'ext', 'contable/bancos'],
        ['/escritura/', 'docu', 'mercantil/escrituras'],
        ['/acta/', 'acta', 'mercantil/actas'],
        ['/poder/', 'poder', 'mercantil/poderes'],
        ['/dni|nie|pasaporte|\bcif\b/', 'docu', 'ident/dni'],
        ['/certificado.*(digital|fnmt)|\.p12|\.pfx/', 'cert', 'ident/certdig'],
        ['/titularidad|certificado bancario/', 'cert', 'ident/bancarios'],
        ['/corriente|certificado/', 'cert', 'fiscal/certificados'],
        ['/ticket|tique/', 'tick', 'contable/recibidas'],
        ['/recibo/', 'rec', 'contable/recibidas'],
        ['/poliza|seguro/', 'docu', 'otros/polizas'],
        ['/permiso de circulacion|ficha tecnica|itv/', 'docu', 'otros/vehiculos'],
        ['/\bcv\b|curriculum/', 'cv', 'laboral/altas'],
    ];

    /** @return array{tipo: string, carpeta: string, fecha: string, nombre: string} */
    public static function proponer(string $nombre, ?string $nombreCliente = null): array
    {
        $n = Str::lower(Str::ascii($nombre));
        preg_match('/\.[a-z0-9]{2,4}$/i', $nombre, $m);
        $ext = strtolower($m[0] ?? '.pdf');

        $fecha = now()->toDateString();
        if (preg_match('/(20\d{2})[-_]?(\d{2})[-_]?(\d{2})/', $n, $dm) && checkdate((int) $dm[2], (int) $dm[3], (int) $dm[1])) {
            $fecha = $dm[1].'-'.$dm[2].'-'.$dm[3];
        }

        $tipo = 'docu';
        $carpeta = 'otros/varios';
        $pre = preg_match('/^\d{8}_(fra|alb|rec|nom|mod|ext|tick|contr|acta|cert|poder|cv|docu)_/', $n, $pm);
        if ($pre) {
            $tipo = $pm[1];
            $carpeta = self::PREFIJOS[$tipo];
        } else {
            foreach (self::REGLAS as [$re, $t, $c]) {
                if (preg_match($re, $n)) {
                    $tipo = $t;
                    $carpeta = $c;
                    break;
                }
            }
        }

        // Factura con el nombre del propio cliente → emitida
        if ($tipo === 'fra' && $nombreCliente) {
            $primera = explode('-', self::slug($nombreCliente))[0] ?? '';
            if ($primera !== '' && str_contains($n, $primera)) {
                $carpeta = 'contable/emitidas';
            }
        }

        $base = preg_replace('/\.[a-z0-9]{2,4}$/i', '', $nombre);
        $nuevo = $pre ? $nombre : str_replace('-', '', $fecha).'_'.$tipo.'_'.(self::slug(preg_replace('/(20\d{2})[-_]?(\d{2})[-_]?(\d{2})/', '', $base)) ?: 'documento').$ext;

        return ['tipo' => $tipo, 'carpeta' => $carpeta, 'fecha' => $fecha, 'nombre' => $nuevo];
    }

    public static function slug(?string $s): string
    {
        $s = Str::lower(Str::ascii((string) $s));
        $s = trim(preg_replace('/[^a-z0-9]+/', '-', $s), '-');

        return substr($s, 0, 28);
    }

    /** Carpetas válidas «grupo/sub» */
    public static function carpetasValidas(): array
    {
        $out = [];
        foreach (config('agcloud.carpetas') as $g => [$l, $subs]) {
            foreach ($subs as $s => $sl) {
                $out[$g.'/'.$s] = $l.' · '.$sl;
            }
        }

        return $out;
    }

    /** Nombre de archivo seguro (sin rutas ni caracteres de control) */
    public static function nombreSeguro(string $n): string
    {
        $n = basename(str_replace('\\', '/', $n));
        $n = preg_replace('/[\x00-\x1F\x7F<>:"\/\\\\|?*]+/u', '_', $n);

        return mb_substr(trim($n) ?: 'documento', 0, 180);
    }
}
