<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Parámetros normativos de nómina con fecha de efecto y fuente (bases y tipos de cotización, SMI, retenciones de IRPF, prestación de IT).
 * Sustituyen a las constantes que había en el código. Los de 2026 se cargan contrastados con resúmenes publicados de la norma;
 * quedan marcados como «contrastado con resúmenes» hasta que alguien los compruebe en el BOE y los marque como verificados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parametros_normativos', function (Blueprint $t) {
            $t->id();
            $t->string('clave', 30); // cotizacion | smi | irpf | it
            $t->date('desde');
            $t->date('hasta')->nullable();
            $t->json('valor');
            $t->string('fuente', 500);
            $t->string('estado', 20)->default('pendiente'); // pendiente | resumen | verificado
            $t->text('notas')->nullable();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('verificado_at')->nullable();
            $t->timestamps();
            $t->unique(['clave', 'desde']);
        });
        $ahora = now();
        $nota = 'Cifras contrastadas con varios resúmenes publicados de la norma; el BOE no era accesible desde el entorno de desarrollo. Compruébalas en el BOE y márcalas como verificadas.';
        $filas = [
            ['cotizacion', [
                'base_max' => 5101.20,
                'base_min' => ['1' => 1989.30, '2' => 1649.70, '3' => 1435.20, '4' => 1424.40, '5' => 1424.40, '6' => 1424.40, '7' => 1424.40],
                'base_min_dia' => ['8' => 47.48, '9' => 47.48, '10' => 47.48, '11' => 47.48],
                // [trabajador, empresa] en tanto por uno
                'tipos' => ['cc' => [0.047, 0.236], 'mei' => [0.0015, 0.0075], 'des_ind' => [0.0155, 0.055], 'des_tmp' => [0.016, 0.067], 'fp' => [0.001, 0.006], 'fog' => [0, 0.002]],
                // Cotización adicional de solidaridad: tramos sobre el exceso de la base máxima (hasta el 10 %, del 10 % al 50 %, más del 50 %) y tipo total en %
                'solidaridad' => [[0.10, 1.15], [0.50, 1.25], [null, 1.46]],
            ], 'Orden PJC/297/2026, de 30 de marzo (BOE 31/03/2026, BOE-A-2026-7296), con efectos desde el 1 de enero; base máxima: Real Decreto-ley 3/2026, art. 3; cotización adicional de solidaridad: art. 19 bis y disposición transitoria 42.ª del texto refundido de la LGSS', 'resumen'],
            ['smi', ['anual' => 17094.0, 'mensual' => 1221.0, 'diario' => 40.70],
                'Real Decreto 126/2026, de 18 de febrero (BOE 19/02/2026, BOE-A-2026-3815), efectos económicos del 1 de enero al 31 de diciembre de 2026', 'resumen'],
            ['irpf', [
                'escala' => [[12450, 19], [20200, 24], [35200, 30], [60000, 37], [300000, 45], [null, 47]],
                // Límites excluyentes de retención: situación => [sin hijos, 1 hijo, 2 o más]
                'limites' => ['1' => [0, 17644, 18694], '2' => [17197, 18130, 19262], '3' => [15876, 16342, 16867]],
                // Reducción por obtención de rendimientos del trabajo (art. 20 LIRPF)
                'reduccion' => ['max' => 7302, 'r1' => 14852, 'f1' => 1.75, 'r2' => 17673.52, 'b2' => 2364.34, 'f2' => 1.14, 'r3' => 19747.5],
                'tipo_min_temporal' => 2,
            ], 'Ley 35/2006 del IRPF, arts. 20 y 101; Reglamento del IRPF (RD 439/2007), arts. 80 a 86 en la redacción del RD 142/2024; algoritmo de cálculo de retenciones de la AEAT para 2026', 'resumen'],
            ['it', [
                // Contingencias comunes: [día desde, día hasta, %, a cargo de]; profesionales: % desde el primer día de baja
                'cc' => [[4, 15, 60, 'empresa'], [16, 20, 60, 'delegado'], [21, null, 75, 'delegado']],
                'at' => 75,
            ], 'LGSS (RDLeg 8/2015), arts. 169 a 173; Decreto 3158/1966, art. 2 (porcentajes generales del Régimen General)', 'pendiente'],
        ];
        foreach ($filas as [$k, $v, $f, $e]) {
            DB::table('parametros_normativos')->insert(['clave' => $k, 'desde' => '2026-01-01', 'hasta' => '2026-12-31', 'valor' => json_encode($v), 'fuente' => $f, 'estado' => $e,
                'notas' => $e === 'resumen' ? $nota : 'Porcentajes legales generales; no cubren regímenes especiales. Pendiente de comprobar en el BOE.', 'created_at' => $ahora, 'updated_at' => $ahora]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('parametros_normativos');
    }
};
