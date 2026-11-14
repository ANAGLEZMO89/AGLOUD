<?php

namespace App\Services;

use App\Models\NominaMes;
use Illuminate\Support\Carbon;

/**
 * Procesos posteriores a la nómina (fase 8): seguros sociales, CRA, remesa, asiento y modelos 111 y 190 a partir de las nóminas
 * EMITIDAS (su foto del cálculo), para que una rectificación (reabrir y volver a emitir) se refleje en todos ellos y nada se cuente dos veces.
 * Lo no emitido se trata como provisional y se dice. Nada de esto envía ficheros a la TGSS, al SEPE ni a la AEAT.
 */
class Posteriores
{
    /** Pasos de un proceso con su estado real en AG Cloud: hecho | aqui (se hace en esta pantalla) | fuera (se hace en la sede oficial) | no */
    public const PASOS = ['preparar' => 'Preparar datos', 'fichero' => 'Generar fichero', 'descargar' => 'Descargar', 'presentar' => 'Presentar', 'respuesta' => 'Respuesta oficial', 'conciliar' => 'Conciliar'];

    /** Recibos de un trabajador en el mes: [[recibo, n, emitida]] */
    public static function recibos(array $x): array
    {
        $out = [['Nómina', $x['n'], ($x['n']['estado'] ?? null) === 'Emitida']];
        foreach ($x['otros'] ?? [] as $o) {
            $out[] = [$o['recibo'], $o['n'], ($o['n']['estado'] ?? null) === 'Emitida'];
        }

        return $out;
    }

    /** Estado de emisión del mes: [total de recibos, emitidos, pendientes (texto por recibo)] */
    public static function estado(array $noms): array
    {
        [$tot, $em, $pend] = [0, 0, []];
        foreach ($noms as $x) {
            foreach (self::recibos($x) as [$r, $n, $e]) {
                $tot++;
                $e ? $em++ : $pend[] = $x['w']['nombre'].' ('.Nomina::RECIBOS[$r].': '.(($n['estado'] ?? null) ? 'borrador' : 'sin guardar').')';
            }
        }

        return ['total' => $tot, 'emitidas' => $em, 'pendientes' => $pend];
    }

    /**
     * Liquidación del mes: base y cuotas de la nómina, el finiquito y los salarios de tramitación (sin la paga extra en recibo aparte, que ya
     * cotiza con la prorrata, ni los atrasos, que van en liquidación complementaria de su mes). Incluye la IT en pago delegado a compensar.
     */
    public static function seguros(array $noms): array
    {
        $filas = [];
        $atr = [];
        foreach ($noms as $x) {
            if (Nomina::esAutonomo($x['w'])) {
                continue;
            }
            $f = ['w' => $x['w'], 'base' => 0.0, 'baseAT' => 0.0, 'ssT' => 0.0, 'ssE' => 0.0, 'deleg' => 0.0, 'provisional' => false];
            foreach (self::recibos($x) as [$r, $n, $e]) {
                if ($r === 'Atrasos') {
                    foreach ($n['lineas'] ?? [] as $l) {
                        if (preg_match('/^atr(\d+)$/', (string) ($l['id'] ?? ''), $mm)) {
                            $atr[(int) $mm[1]][] = ['w' => $x['w'], 'importe' => (float) $l['importe'], 'emitida' => $e];
                        }
                    }

                    continue;
                }
                foreach (['base', 'baseAT', 'ssT', 'ssE', 'deleg'] as $k) {
                    $f[$k] += (float) ($n[$k] ?? 0);
                }
                $f['provisional'] = $f['provisional'] || ! $e;
            }
            $filas[] = $f;
        }
        ksort($atr);

        return ['filas' => $filas, 'atrasos' => $atr];
    }

    /** Clave CRA de una línea: el código del concepto del convenio si lo tiene; si no, por su naturaleza */
    public static function claveCra(array $l): string
    {
        $id = (string) ($l['id'] ?? '');
        $cl = $l['clase'] ?? 'salarial';

        return match (true) {
            $id === 'sb' => 'sb',
            $id === 'pl' || $id === 'ant' => 'pl',
            $cl === 'extra' => ($l['cobro'] ?? '') === 'prorrateada' ? 'pror' : 'extra',
            $cl === 'hx' => 'hx',
            $cl === 'no_salarial' => $id === 'km' ? 'km' : 'die',
            $cl === 'tributa' => 'it',
            $cl === 'especie' => 'esp',
            $cl === 'exento' => 'ind',
            default => 'otr',
        };
    }

    /** Filas de la CRA del mes: por trabajador y código, de los recibos (emitidos o provisionales) */
    public static function cra(array $noms, array $P): array
    {
        $filas = [];
        foreach ($noms as $x) {
            if (Nomina::esAutonomo($x['w'])) {
                continue;
            }
            $acc = [];
            foreach (self::recibos($x) as [$r, $n, $e]) {
                foreach ($n['lineas'] ?? [] as $l) {
                    if ((float) ($l['importe'] ?? 0) == 0) {
                        continue;
                    }
                    $k = self::claveCra($l);
                    $cod = ($l['cra'] ?? '') !== '' ? (string) $l['cra'] : (string) ($P['cra'][$k] ?? '');
                    $nom = ($l['cra'] ?? '') !== '' ? preg_replace('/ \(.*\)$/', '', $l['concepto']) : Nomina::CRA[$k];
                    $kk = $cod.'|'.$nom;
                    $acc[$kk] ??= ['w' => $x['w'], 'k' => $k, 'l' => $nom, 'cod' => $cod, 'v' => 0.0, 'provisional' => false, 'propio' => ($l['cra'] ?? '') !== ''];
                    $acc[$kk]['v'] += (float) $l['importe'];
                    $acc[$kk]['provisional'] = $acc[$kk]['provisional'] || ! $e;
                }
            }
            foreach ($acc as $f) {
                $f['v'] = round($f['v'], 2);
                $filas[] = $f;
            }
        }

        return $filas;
    }

    /** Recibos del mes emitidos o reabiertos después de una fecha (p. ej. de confirmar la liquidación): hay que regularizar */
    public static function cambiadosDesde(int $clienteId, int $anio, int $mes, ?Carbon $desde): array
    {
        if (! $desde) {
            return [];
        }
        $out = [];
        foreach (NominaMes::where('cliente_id', $clienteId)->where('anio', $anio)->where('mes', $mes)->with('trabajador')->get() as $g) {
            $reab = collect($g->datos['historial'] ?? [])->contains(fn ($h) => Carbon::parse($h['reabierta_at'])->greaterThan($desde));
            if ($reab || ($g->emitida_at && $g->emitida_at->greaterThan($desde)) || (! $g->emitida() && $g->updated_at?->greaterThan($desde))) {
                $out[] = ($g->trabajador?->datos['nombre'] ?? 'Trabajador').' ('.Nomina::RECIBOS[$g->recibo].')';
            }
        }

        return $out;
    }
}
