<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Convenio;
use App\Models\ConvenioVersion;
use App\Models\EmpresaConvenio;
use App\Models\JornadaAusencia;
use App\Models\NominaMes;
use App\Models\Trabajador;
use App\Models\TrabajadorCondicion;
use App\Models\User;
use App\Services\Calendario;
use App\Services\Nomina;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Fase 6 laboral: pagas por periodo de devengo, atrasos, finiquito con lo ya pagado, rectificación, emisión sin duplicados y en bloque */
class EmisionNominaTest extends TestCase
{
    use RefreshDatabase;

    private Cliente $a;

    private User $as;

    private ConvenioVersion $v;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-12-20 10:00:00');
        CarbonImmutable::setTestNow('2026-12-20 10:00:00');
        $this->a = $this->cliente('Construcciones Uno SL', 'B21890124');
        $this->as = $this->usuario(User::ASESOR);
        $cv = Convenio::create(['nombre' => 'Construcción de Huelva', 'estado' => 'aprobado']);
        EmpresaConvenio::create(['cliente_id' => $this->a->id, 'convenio_id' => $cv->id]);
        $this->v = ConvenioVersion::create(['convenio_id' => $cv->id, 'nombre' => '2026', 'estado' => 'aprobado', 'efectos_desde' => '2025-01-01', 'reglas' => ['jornada' => '1800']]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function en()
    {
        return $this->actingAs($this->as)->withSession(['cliente_activo' => $this->a->id]);
    }

    private function trab(array $d = []): Trabajador
    {
        $d = array_merge(['nombre' => 'Ana Pérez Gil', 'nif' => '12345678Z', 'naf' => '211234567890', 'contrato' => Nomina::CONTRATOS[0], 'grupo' => 7, 'salTipo' => 'Mensual', 'salBase' => 1500,
            'pagas' => 14, 'jor' => 100, 'horas' => '40', 'alta' => '2026-01-01'], $d);

        return Trabajador::create(['cliente_id' => $this->a->id, 'estado' => 'Alta', 'alta' => $d['alta'], 'contrato' => '100', 'datos' => $d]);
    }

    private function w(Trabajador $t, array $extra = []): array
    {
        Nomina::olvidarGuardadas();
        Calendario::olvidar();

        return array_merge(Nomina::rellenar($t->fresh()->ficha(), $this->a), $extra);
    }

    private function linea(array $doc, string $id): ?array
    {
        return collect($doc['lineas'])->firstWhere('id', $id);
    }

    /** Emite la nómina de un mes con su cálculo automático */
    private function emitir(Trabajador $t, int $m, int $anio = 2026): NominaMes
    {
        $w = $this->w($t);
        $doc = Nomina::conceptos($w, $m, [], null, $anio) + ['recibo' => 'Nómina'];

        return NominaMes::create(['cliente_id' => $this->a->id, 'trabajador_id' => $t->id, 'anio' => $anio, 'mes' => $m, 'recibo' => 'Nómina', 'estado' => 'Emitida', 'emitida_at' => now(),
            'datos' => $doc + ['calc' => Nomina::computar(Nomina::paraPeriodo($w, $anio, $m), $doc, 0)]]);
    }

    public function test_pagas_extra_segun_su_periodo_de_devengo(): void
    {
        // Alta el 1 de marzo: Navidad (devengo anual de enero a diciembre) = 306 de 365 días
        $t = $this->trab(['alta' => '2026-03-01']);
        $pn = $this->linea(Nomina::conceptos($this->w($t), 12, [], null, 2026), 'pn');
        $this->assertSame(round(1500 * 306 / 365, 2), $pn['importe']);
        $this->assertStringContainsString('306 / 365 días devengados', $pn['expl']);
        $this->assertStringContainsString('01/01/2026 al 31/12/2026', $pn['expl']);
        // Paga semestral del convenio (enero a junio): 122 de 181 días
        $this->v->update(['reglas' => ['jornada' => '1800', 'extras' => [
            ['nombre' => 'Paga de verano', 'mes' => 6, 'calculo' => 'mensualidad', 'devengo' => ['tipo' => 'semestral'], 'art' => 'art. 25'],
            ['nombre' => 'Paga de Navidad', 'mes' => 12, 'calculo' => 'mensualidad', 'devengo' => ['tipo' => 'semestral']],
        ]]]);
        $pv = $this->linea(Nomina::conceptos($this->w($t), 6, [], null, 2026), 'pv');
        $this->assertSame(round(1500 * 122 / 181, 2), $pv['importe']);
        $this->assertStringContainsString('art. 25', $pv['regla']);
        // Un permiso no retribuido no genera paga: 184 − 5 días del segundo semestre
        $t2 = $this->trab(['nombre' => 'Luis Gil Ros', 'nif' => '00000000T']);
        JornadaAusencia::create(['cliente_id' => $this->a->id, 'trabajador_id' => $t2->id, 'tipo' => 'Permiso no retribuido', 'desde' => '2026-09-07', 'hasta' => '2026-09-11', 'estado' => 'confirmada', 'origen' => 'nomina']);
        $pn2 = $this->linea(Nomina::conceptos($this->w($t2), 12, [], null, 2026), 'pn');
        $this->assertSame(round(1500 * 179 / 184, 2), $pn2['importe']);
        $this->assertStringContainsString('descontados 5', $pn2['expl']);
    }

    public function test_atrasos_por_revision_salarial_retroactiva(): void
    {
        $t = $this->trab();
        $this->emitir($t, 9);
        $this->emitir($t, 10);
        // Revisión salarial con efectos desde enero: 1.600 €
        TrabajadorCondicion::create(['cliente_id' => $this->a->id, 'trabajador_id' => $t->id, 'efectos_desde' => '2026-01-01', 'datos' => ['salTipo' => 'Mensual', 'salBase' => 1600, 'jor' => 100]]);
        $t->update(['datos' => array_merge($t->datos, ['salBase' => 1600])]);
        $doc = Nomina::conceptosRecibo($this->w($t), 'Atrasos', 2026, 11);
        $L = collect($doc['lineas'])->keyBy('concepto');
        $this->assertSame([100.0, 100.0], [$L['Atrasos de septiembre de 2026']['importe'], $L['Atrasos de octubre de 2026']['importe']]);
        $this->assertStringContainsString('Salario base mensual: 1.500,00 → 1.600,00 (+100,00)', $L['Atrasos de octubre de 2026']['expl']);
        // Los meses sin nómina emitida no generan atrasos
        $this->assertFalse($L->has('Atrasos de agosto de 2026'));
    }

    public function test_finiquito_con_lo_ya_pagado(): void
    {
        $t = $this->trab();
        // Baja el 15 de agosto: Navidad devengada del 01/01 al 15/08 (227 días); verano del 01/07 al 15/08 (46 días del devengo julio-junio)
        $doc = Nomina::conceptosRecibo($this->w($t, ['baja' => '2026-08-15']), 'Finiquito', 2026, 8);
        $L = collect($doc['lineas'])->keyBy('id');
        $this->assertSame(round(1500 * 227 / 365, 2), $L['pppn']['importe']);
        $this->assertSame(round(1500 * 46 / 365, 2), $L['pppv']['importe']);
        // Baja el 20 de diciembre con la nómina de diciembre ya emitida con la paga de Navidad: no se paga dos veces
        $this->emitir($t, 12);
        $doc = Nomina::conceptosRecibo($this->w($t, ['baja' => '2026-12-20']), 'Finiquito', 2026, 12);
        $pn = collect($doc['lineas'])->firstWhere('id', 'pppn');
        $this->assertSame(0.0, $pn['importe']);
        $this->assertStringContainsString('ya pagados en nóminas emitidas', $pn['expl']);
        $this->assertTrue(collect($doc['avisosCal'])->contains(fn ($x) => str_contains($x[1], 'cobrados de más')));
    }

    public function test_conceptos_fijos_con_mes_de_inicio_y_fin(): void
    {
        $t = $this->trab();
        $t->update(['datos' => array_merge($t->datos, ['concFijos' => [
            ['cod' => '05', 'concepto' => 'Mejora voluntaria', 'clase' => 'salarial', 'importe' => 50, 'desde' => '2026-01', 'hasta' => '2026-10'],
            ['cod' => '17', 'concepto' => 'Plus de responsabilidad', 'clase' => 'salarial', 'importe' => 80, 'desde' => '2026-12', 'hasta' => ''],
        ]])]);
        $nov = collect(Nomina::conceptos($this->w($t), 11, [], null, 2026)['lineas'])->pluck('concepto');
        $dic = collect(Nomina::conceptos($this->w($t), 12, [], null, 2026)['lineas'])->pluck('concepto');
        $oct = collect(Nomina::conceptos($this->w($t), 10, [], null, 2026)['lineas'])->pluck('concepto');
        $this->assertFalse($nov->contains('Mejora voluntaria') || $nov->contains('Plus de responsabilidad'));
        $this->assertTrue($dic->contains('Plus de responsabilidad') && $oct->contains('Mejora voluntaria'));
        // Desde la ficha se cambia el mes final
        $this->en()->post(route('laboral.guardar', $t->id), array_merge($t->fresh()->datos, ['_ficha' => 1, '_fijos' => 1, 'fijos' => [0 => ['desde' => '2026-01', 'hasta' => '2026-11'], 1 => ['quitar' => 1]]]))
            ->assertSessionHasNoErrors();
        $this->assertSame([['Mejora voluntaria', '2026-11']], collect($t->fresh()->datos['concFijos'])->map(fn ($x) => [$x['concepto'], $x['hasta']])->all());
    }

    public function test_emision_sin_duplicados_y_rectificacion_con_historial(): void
    {
        $t = $this->trab();
        $base = ['anio' => 2026, 'mes' => 11, 'recibo' => 'Nómina', 'l' => [['id' => 'sb', 'cod' => '01', 'concepto' => 'Salario base mensual', 'clase' => 'salarial', 'importe' => '1.500,00', 'activo' => '1']]];
        $this->en()->post(route('laboral.nomina.guardar', $t->id), $base + ['accion' => 'emitir'])->assertSessionHasNoErrors();
        $this->en()->post(route('laboral.nomina.guardar', $t->id), $base + ['accion' => 'emitir'])->assertSessionHasErrors('ficha');
        $this->assertSame(1, NominaMes::count());
        $g = NominaMes::firstOrFail();
        $this->en()->post(route('laboral.nomina.guardar', $t->id), ['anio' => 2026, 'mes' => 11, 'accion' => 'reabrir', 'motivo' => 'Faltaba el plus'])->assertSessionHasNoErrors();
        $this->en()->post(route('laboral.nomina.guardar', $t->id), $base + ['accion' => 'emitir'])->assertSessionHasNoErrors();
        $h = $g->fresh()->datos['historial'];
        $this->assertSame(['Faltaba el plus', 1500.0], [$h[0]['motivo'], (float) $h[0]['dev']]);
        $this->assertSame('Emitida', $g->fresh()->estado);
        // Diferencias con el mes anterior en el editor
        $this->en()->get(route('laboral.integral', ['anio' => 2026, 'mes' => 11, 't' => $t->id]))->assertOk()->assertSee('Diferencias con octubre de 2026')->assertSee('Historial de rectificaciones (1)');
    }

    public function test_emision_del_mes_en_bloque_salta_las_que_tienen_errores(): void
    {
        $t1 = $this->trab();
        $t2 = $this->trab(['nombre' => 'Luis Gil Ros', 'nif' => '00000000T']);
        // IT sin base reguladora: error
        JornadaAusencia::create(['cliente_id' => $this->a->id, 'trabajador_id' => $t2->id, 'tipo' => 'Incapacidad temporal', 'contingencia' => 'cc', 'desde' => '2026-11-10', 'hasta' => '2026-11-20', 'estado' => 'confirmada', 'origen' => 'nomina']);
        $this->en()->post(route('laboral.emitir.mes'), ['anio' => 2026, 'mes' => 11])->assertSessionHas('ok')->assertSessionHasErrors('ficha');
        $this->assertSame([$t1->id], NominaMes::where('estado', 'Emitida')->pluck('trabajador_id')->all());
        $g = NominaMes::firstOrFail();
        $this->assertSame(1500.0, (float) $g->datos['calc']['sb']);
        $this->assertSame('2026-11-30', $g->fecha_pago->toDateString());
        // Repetir no duplica
        $this->en()->post(route('laboral.emitir.mes'), ['anio' => 2026, 'mes' => 11]);
        $this->assertSame(1, NominaMes::where('estado', 'Emitida')->count());
    }
}
