<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Completa los parámetros normativos de 2026:
 *  - IRPF: mínimos personales y familiares, gastos deducibles, límite del 43 % y préstamo de vivienda, cotejados con el algoritmo
 *    oficial de cálculo de retenciones de la AEAT para 2026 (descargado de su sede): pasan a «verificado».
 *  - Cotización: cotización adicional de los contratos de duración determinada inferiores a 30 días (art. 28 de la Orden PJC/297/2026).
 *  - Tarifa de accidentes de trabajo y enfermedades profesionales por CNAE y ocupación: estructura vacía, pendiente de cargar desde el BOE.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ahora = now();
        $irpf = DB::table('parametros_normativos')->where('clave', 'irpf')->where('desde', '2026-01-01')->first();
        if ($irpf) {
            $v = json_decode($irpf->valor, true);
            $v['minimos'] = ['personal' => 5550, 'edad65' => 1150, 'edad75' => 1400, 'desc' => [2400, 2700, 4000, 4500], 'desc3' => 2800, 'asc65' => 1150, 'asc75' => 1400,
                'disc33' => 3000, 'disc65' => 9000, 'asistencia' => 3000];
            $v['gastos'] = ['general' => 2000, 'movilidad' => 2000, 'disc33' => 3500, 'disc65' => 7750];
            $v['limite43'] = ['hasta' => 35200, 'pct' => 43];
            $v['vivienda'] = ['limite' => 33007.20, 'pct' => 2];
            DB::table('parametros_normativos')->where('id', $irpf->id)->update(['valor' => json_encode($v), 'estado' => 'verificado', 'verificado_at' => $ahora, 'updated_at' => $ahora,
                'fuente' => 'Ley 35/2006 del IRPF, arts. 20, 57 a 61 y 101; Reglamento del IRPF (RD 439/2007), arts. 80 a 86 en la redacción del RD 142/2024; algoritmo de cálculo de retenciones de la AEAT para 2026',
                'notas' => 'Escala, límites excluyentes, reducción por rendimientos del trabajo, mínimos, gastos, límite del 43 % y préstamo de vivienda cotejados con el algoritmo oficial de la AEAT para 2026 (sede electrónica de la AEAT).']);
        }
        $cot = DB::table('parametros_normativos')->where('clave', 'cotizacion')->where('desde', '2026-01-01')->first();
        if ($cot) {
            $v = json_decode($cot->valor, true);
            $v['temporal_corto'] = 33.62;
            DB::table('parametros_normativos')->where('id', $cot->id)->update(['valor' => json_encode($v), 'updated_at' => $ahora,
                'fuente' => $cot->fuente.'; cotización adicional de contratos de duración determinada inferiores a 30 días: art. 28 de la Orden PJC/297/2026 y art. 151 LGSS']);
        }
        if (! DB::table('parametros_normativos')->where('clave', 'atep')->exists()) {
            DB::table('parametros_normativos')->insert(['clave' => 'atep', 'desde' => '2026-01-01', 'hasta' => null,
                'valor' => json_encode(['cnae' => (object) [], 'ocupaciones' => (object) []]),
                'fuente' => 'Tarifa de primas para la cotización por accidentes de trabajo y enfermedades profesionales: disposición adicional 61.ª del texto refundido de la LGSS (CNAE-2025)',
                'estado' => 'pendiente', 'notas' => 'Sin cargar: pega la tabla del BOE (cuadro I por CNAE y cuadro II por ocupación). Mientras falte, se usa el tipo de AT y EP indicado a mano en cada empresa.',
                'created_at' => $ahora, 'updated_at' => $ahora]);
        }
    }

    public function down(): void
    {
        DB::table('parametros_normativos')->where('clave', 'atep')->delete();
    }
};
