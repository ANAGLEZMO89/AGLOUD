<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\Factura;
use App\Models\RegistroFacturacion;
use App\Support\Formato;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Emisión de facturas y registros de facturación encadenados
 * (RD 1007/2023 y Orden HAC/1177/2024).
 *
 * - Cada alta o anulación genera un registro con la huella SHA-256 del
 *   registro anterior del mismo emisor: cualquier alteración rompe la cadena.
 * - Modo «No VERI*FACTU»: los registros se conservan en el sistema y NO se
 *   envían a la AEAT (el envío solo se activará cuando se pida y tras validar
 *   los diseños de registro oficiales vigentes).
 */
class Facturacion
{
    public const QR_BASE = 'https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQRNoVerifactu';

    /** Totales a partir de las líneas: [base, ivas[tipo => [base, cuota]], cuota, retencion, suplidos, total] */
    public static function totales(array $lineas, float $retPct = 0): array
    {
        $ivas = [];
        $base = 0.0;
        $sup = 0.0;
        foreach ($lineas as $l) {
            $imp = round((float) $l['q'] * (float) $l['p'], 2);
            if (! empty($l['sup'])) {
                $sup += $imp;

                continue;
            }
            $base += $imp;
            $t = (string) (0 + (float) $l['iva']);
            $ivas[$t] ??= ['base' => 0.0, 'cuota' => 0.0];
            $ivas[$t]['base'] += $imp;
        }
        $cuota = 0.0;
        foreach ($ivas as $t => &$x) {
            $x['base'] = round($x['base'], 2);
            $x['cuota'] = round($x['base'] * (float) $t / 100, 2);
            $cuota += $x['cuota'];
        }
        unset($x);
        krsort($ivas, SORT_NUMERIC);
        $base = round($base, 2);
        $ret = round($base * $retPct / 100, 2);

        return ['base' => $base, 'ivas' => $ivas, 'cuota' => round($cuota, 2), 'retencion' => $ret, 'suplidos' => round($sup, 2), 'total' => round($base + $cuota - $ret + $sup, 2)];
    }

    public static function ahora(): string
    {
        return CarbonImmutable::now('Europe/Madrid')->format('Y-m-d\TH:i:sP');
    }

    private static function imp(float|string $n): string
    {
        return number_format((float) $n, 2, '.', '');
    }

    public static function huellaAlta(string $nif, string $num, string $fechaYmd, string $tf, float|string $cuota, float|string $total, ?string $prev, string $ts): string
    {
        return strtoupper(hash('sha256', 'IDEmisorFactura='.trim($nif).'&NumSerieFactura='.trim($num).'&FechaExpedicionFactura='.self::dmy($fechaYmd)
            .'&TipoFactura='.$tf.'&CuotaTotal='.self::imp($cuota).'&ImporteTotal='.self::imp($total).'&Huella='.($prev ?? '').'&FechaHoraHusoGenRegistro='.$ts));
    }

    public static function huellaAnulacion(string $nif, string $num, string $fechaYmd, ?string $prev, string $ts): string
    {
        return strtoupper(hash('sha256', 'IDEmisorFacturaAnulada='.trim($nif).'&NumSerieFacturaAnulada='.trim($num).'&FechaExpedicionFacturaAnulada='.self::dmy($fechaYmd)
            .'&Huella='.($prev ?? '').'&FechaHoraHusoGenRegistro='.$ts));
    }

    private static function dmy(string $ymd): string
    {
        return implode('-', array_reverse(explode('-', substr($ymd, 0, 10))));
    }

    public static function siguienteNumero(Cliente $c, string $serie, int $anio): int
    {
        return (int) DB::table('facturas')->where('cliente_id', $c->id)->where('serie', $serie)->whereYear('fecha', $anio)->max('numero') + 1;
    }

    public static function numTexto(string $serie, int $anio, int $n): string
    {
        return sprintf('%s-%d-%04d', $serie, $anio, $n);
    }

    private function ultimaHuella(Cliente $c): ?string
    {
        return RegistroFacturacion::withoutGlobalScopes()->where('cliente_id', $c->id)->orderByDesc('id')->lockForUpdate()->value('huella');
    }

    /**
     * Emite una factura. $d ya validado: serie, tf, fecha, receptor_*, lineas, ret_pct, forma_pago, pago_dias, iban, iban_emisor, observaciones, rectifica_id, presupuesto_id.
     */
    public function emitir(Cliente $emisor, array $d, ?int $userId): Factura
    {
        if (! $emisor->nif) {
            throw new InvalidArgumentException('La empresa emisora no tiene NIF: complétalo en su ficha antes de facturar.');
        }

        return DB::transaction(function () use ($emisor, $d, $userId) {
            // Bloquea la cadena del emisor mientras se numera y se encadena
            $prev = $this->ultimaHuella($emisor);
            $fecha = CarbonImmutable::parse($d['fecha']);
            $ultima = DB::table('facturas')->where('cliente_id', $emisor->id)->where('serie', $d['serie'])->max('fecha');
            if ($ultima && $fecha->toDateString() < substr((string) $ultima, 0, 10)) {
                throw new InvalidArgumentException('La fecha no puede ser anterior a la última factura de la serie '.$d['serie'].' ('.Formato::fecha(substr((string) $ultima, 0, 10)).').');
            }
            $n = self::siguienteNumero($emisor, $d['serie'], (int) $fecha->format('Y'));
            $num = self::numTexto($d['serie'], (int) $fecha->format('Y'), $n);
            $t = self::totales($d['lineas'], (float) ($d['ret_pct'] ?? 0));
            $ts = self::ahora();
            $huella = self::huellaAlta($emisor->nif, $num, $fecha->toDateString(), $d['tf'], $t['cuota'], $t['total'], $prev, $ts);

            $f = Factura::create([
                'cliente_id' => $emisor->id, 'serie' => $d['serie'], 'numero' => $n, 'num' => $num, 'tipo' => $d['tf'], 'fecha' => $fecha->toDateString(),
                'receptor_nombre' => $d['receptor_nombre'] ?? null, 'receptor_nif' => $d['receptor_nif'] ?? null, 'receptor_dir' => $d['receptor_dir'] ?? null,
                'datos_fe' => $d['datos_fe'] ?? null, 'modo' => $d['modo'] ?? 'normal',
                'lineas' => $d['lineas'], 'ivas' => $t['ivas'], 'ret_pct' => $d['ret_pct'] ?? 0,
                'base' => $t['base'], 'cuota' => $t['cuota'], 'retencion' => $t['retencion'], 'suplidos' => $t['suplidos'], 'total' => $t['total'],
                'forma_pago' => $d['forma_pago'] ?? null, 'pago_dias' => (int) ($d['pago_dias'] ?? 0), 'iban' => $d['iban'] ?? null, 'iban_emisor' => $d['iban_emisor'] ?? null,
                'observaciones' => $d['observaciones'] ?? null, 'rectifica_id' => $d['rectifica_id'] ?? null, 'presupuesto_id' => $d['presupuesto_id'] ?? null,
                'estado' => 'Emitida', 'huella' => $huella, 'created_by' => $userId,
            ]);
            RegistroFacturacion::create([
                'cliente_id' => $emisor->id, 'factura_id' => $f->id, 'tipo' => 'Alta', 'nif_emisor' => $emisor->nif, 'num' => $num, 'fecha' => $f->fecha->toDateString(),
                'tipo_factura' => $d['tf'], 'cuota' => $t['cuota'], 'total' => $t['total'], 'fecha_hora_huso' => $ts, 'huella_anterior' => $prev, 'huella' => $huella,
            ]);

            return $f;
        });
    }

    public function anular(Factura $f): RegistroFacturacion
    {
        $emisor = Cliente::findOrFail($f->cliente_id);

        return DB::transaction(function () use ($f, $emisor) {
            $f = Factura::lockForUpdate()->findOrFail($f->id);
            if ($f->estado === 'Anulada') {
                throw new InvalidArgumentException('La factura ya está anulada.');
            }
            $prev = $this->ultimaHuella($emisor);
            $ts = self::ahora();
            $nif = RegistroFacturacion::withoutGlobalScopes()->where('factura_id', $f->id)->where('tipo', 'Alta')->value('nif_emisor') ?? $emisor->nif;
            $r = RegistroFacturacion::create([
                'cliente_id' => $f->cliente_id, 'factura_id' => $f->id, 'tipo' => 'Anulación', 'nif_emisor' => $nif, 'num' => $f->num, 'fecha' => $f->fecha->toDateString(),
                'tipo_factura' => $f->tipo, 'cuota' => 0, 'total' => 0, 'fecha_hora_huso' => $ts, 'huella_anterior' => $prev,
                'huella' => self::huellaAnulacion($nif, $f->num, $f->fecha->toDateString(), $prev, $ts),
            ]);
            $f->estado = 'Anulada';
            $f->save();

            return $r;
        });
    }

    /** Recalcula toda la cadena del emisor. Devuelve null si está íntegra o el número del primer registro roto. */
    public function verificar(Cliente $c): ?string
    {
        $prev = null;
        foreach (RegistroFacturacion::withoutGlobalScopes()->where('cliente_id', $c->id)->orderBy('id')->cursor() as $r) {
            $h = $r->tipo === 'Alta'
                ? self::huellaAlta($r->nif_emisor, $r->num, $r->fecha->toDateString(), $r->tipo_factura, $r->cuota, $r->total, $prev, $r->fecha_hora_huso)
                : self::huellaAnulacion($r->nif_emisor, $r->num, $r->fecha->toDateString(), $prev, $r->fecha_hora_huso);
            if (($r->huella_anterior ?? null) !== $prev || $h !== $r->huella) {
                return $r->num.' ('.$r->tipo.')';
            }
            $prev = $r->huella;
        }

        return null;
    }

    public static function urlQr(string $nif, Factura $f): string
    {
        return self::QR_BASE.'?nif='.rawurlencode($nif).'&numserie='.rawurlencode($f->num).'&fecha='.$f->fecha->format('d-m-Y').'&importe='.self::imp($f->total);
    }

    public static function qrSvg(string $texto): string
    {
        $w = new Writer(new ImageRenderer(new RendererStyle(180, 1), new SvgImageBackEnd));

        return $w->writeString($texto, 'UTF-8', ErrorCorrectionLevel::M());
    }
}
