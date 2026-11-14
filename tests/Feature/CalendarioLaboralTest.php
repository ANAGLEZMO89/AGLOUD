<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Convenio;
use App\Models\ConvenioVersion;
use App\Models\EmpresaConvenio;
use App\Models\JornadaAusencia;
use App\Models\JornadaDia;
use App\Models\LaboralHora;
use App\Models\NominaMes;
use App\Models\Trabajador;
use App\Models\TrabajadorCondicion;
use App\Models\User;
use App\Services\Calendario;
use App\Services\Nomina;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

/** Fase 5 laboral: condiciones con fecha de efecto, calendario, ausencias revisadas, IT, horas y propuestas del control horario */
class CalendarioLaboralTest extends TestCase
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
        $this->v = ConvenioVersion::create(['convenio_id' => $cv->id, 'nombre' => '2026', 'estado' => 'aprobado', 'efectos_desde' => '2026-01-01', 'reglas' => ['jornada' => '1800']]);
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

    private function doc(Trabajador $t, int $m): array
    {
        Nomina::olvidarGuardadas();
        Nomina::olvidarReglas();

        return Nomina::conceptos(Nomina::rellenar($t->fresh()->ficha(), $this->a), $m, [], null, 2026);
    }

    private function lineas(Trabajador $t, int $m): Collection
    {
        return collect($this->doc($t, $m)['lineas'])->keyBy('concepto');
    }

    private function aus(Trabajador $t, string $tipo, string $desde, string $hasta, array $extra = []): JornadaAusencia
    {
        return JornadaAusencia::create(['cliente_id' => $this->a->id, 'trabajador_id' => $t->id, 'tipo' => $tipo, 'desde' => $desde, 'hasta' => $hasta, 'estado' => 'confirmada', 'origen' => 'nomina'] + $extra);
    }

    public function test_calendario_con_dias_de_trabajo_y_festivos_de_la_empresa(): void
    {
        $t = $this->trab();
        $w = Nomina::rellenar($t->ficha(), $this->a);
        // Diciembre 2026: 23 días de lunes a viernes; festivos en martes 8 y viernes 25
        $c = Calendario::mes($w, 2026, 12);
        $this->assertSame([31, 21, 21, 21], [$c['alta'], $c['laborables'], $c['trabajados'], $c['efectivos']]);
        $this->assertSame('Inmaculada Concepción', $c['dias']['2026-12-08']['fest']);
        // Si trabaja de lunes a sábado: + 4 sábados
        $this->assertSame(25, Calendario::mes($w + ['diasSemana' => [1, 2, 3, 4, 5, 6]], 2026, 12)['laborables']);
        // Febrero tiene 28 días; meses de 30 y 31 días, con el mes completo, cobran el salario entero
        foreach ([2, 4, 12] as $m) {
            $this->assertSame(1500.0, $this->lineas($t, $m)['Salario base mensual']['importe']);
        }
    }

    public function test_vacaciones_no_descuentan_y_ausencia_no_retribuida_si(): void
    {
        $t = $this->trab();
        $this->aus($t, 'Vacaciones', '2026-12-14', '2026-12-18');
        $this->aus($t, 'Permiso no retribuido', '2026-12-02', '2026-12-04');
        $d = $this->doc($t, 12);
        $L = collect($d['lineas'])->keyBy('id');
        // 1.500 × (30 − 3) / 30 = 1.350
        $this->assertSame(1350.0, $L['sb']['importe']);
        $this->assertStringContainsString('descontados 3 días', $L['sb']['expl']);
        $this->assertSame(['laborables' => 21, 'trabajados' => 18, 'efectivos' => 13, 'noRetrib' => 3, 'it' => 0, 'pagados' => 28], $d['calendario']);
        $this->assertTrue(collect($d['avisosCal'])->contains(fn ($x) => $x[0] === 'aviso' && str_contains($x[1], 'no retribuida')));
    }

    public function test_plus_por_dia_efectivo_y_por_dia_trabajado(): void
    {
        $this->v->update(['estado' => 'borrador']);
        $base = ['naturaleza' => 'salarial', 'calculo' => 'fijo', 'cotiza' => 'si', 'tributa' => 'si', 'redondeo' => '2d', 'automatico' => '1', 'prop_jornada' => '1', 'prop_periodo' => '1'];
        $this->en()->post(route('laboral.convenios.seccion', 'conceptos'), ['version_id' => $this->v->id, 'conceptos' => [
            $base + ['nombre' => 'Plus de transporte', 'unidad' => 'dia_efectivo', 'valor' => '3,00'],
            $base + ['nombre' => 'Plus de asistencia', 'unidad' => 'dia_trabajado', 'valor' => '2,00'],
        ]])->assertSessionHasNoErrors();
        $this->v->update(['estado' => 'aprobado']);
        $t = $this->trab();
        $this->aus($t, 'Vacaciones', '2026-12-14', '2026-12-15');
        $L = $this->lineas($t, 12);
        $this->assertSame(19 * 3.0, $L['Plus de transporte']['importe']);
        $this->assertSame(21 * 2.0, $L['Plus de asistencia']['importe']);
        $this->assertStringContainsString('19 días efectivos', $L['Plus de transporte']['expl']);
    }

    public function test_ausencias_del_control_horario_no_cuentan_hasta_revisarlas(): void
    {
        $t = $this->trab();
        $this->en()->post(route('horario.ausencia'), ['trabajador' => $t->id, 'tipo' => 'Ausencia injustificada', 'desde' => '2026-12-09', 'hasta' => '2026-12-10'])->assertSessionHasNoErrors();
        $a = JornadaAusencia::firstOrFail();
        $this->assertSame(['propuesta', 'control_horario'], [$a->estado, $a->origen]);
        $d = $this->doc($t, 12);
        $this->assertSame(1500.0, collect($d['lineas'])->firstWhere('id', 'sb')['importe']);
        $this->assertTrue(collect($d['avisosCal'])->contains(fn ($x) => str_contains($x[1], 'pendiente')));
        $this->en()->post(route('laboral.cal.revisar', $a->id), ['accion' => 'confirmar', 'anio' => 2026, 'mes' => 12])->assertSessionHasNoErrors();
        $this->assertSame(1400.0, collect($this->doc($t, 12)['lineas'])->firstWhere('id', 'sb')['importe']);
        // Ya en la nómina: no se borra desde el control horario
        $this->en()->delete(route('horario.ausencia.borrar', $a->id))->assertSessionHasErrors('horario');
        $this->assertNotNull($a->fresh());
    }

    public function test_it_comun_con_prestacion_y_mejora_del_convenio_por_separado(): void
    {
        $this->v->update(['reglas' => ['jornada' => '1800', 'it' => ['cc' => [['desde' => 1, 'hasta' => null, 'pct' => 100.0, 'hosp' => false]], 'at' => [], 'base' => '', 'art' => 'art. 30']]]);
        $t = $this->trab();
        $this->aus($t, 'Incapacidad temporal', '2026-11-03', '2026-11-30', ['contingencia' => 'cc', 'base_reguladora' => 50]);
        $d = $this->doc($t, 11);
        $L = collect($d['lineas'])->keyBy('concepto');
        // Salario: 30 − 28 días de IT = 2 días → 100 €
        $this->assertSame(100.0, collect($d['lineas'])->firstWhere('id', 'sb')['importe']);
        $this->assertSame(12 * 30.0, $L['Prestación IT a cargo de la empresa (60 %, días 4 a 15)']['importe']);
        $this->assertSame(5 * 30.0, $L['Prestación IT pago delegado (60 %, días 16 a 20)']['importe']);
        $this->assertSame(8 * 37.5, $L['Prestación IT pago delegado (75 %, desde el día 21)']['importe']);
        $mej = collect($d['lineas'])->where('cod', '41')->values();
        // Mejora hasta el 100 %: días 1-3 (100 %), 4-15 y 16-20 (40 %), desde el 21 (25 %)
        $this->assertSame([150.0, 240.0, 100.0, 100.0], $mej->pluck('importe')->all());
        $this->assertTrue(collect($d['lineas'])->where('cod', '40')->every(fn ($l) => $l['clase'] === 'tributa'));
        // Cotiza por la base reguladora de los 28 días de IT
        $this->assertSame(1400.0, $d['itCot']);
        $n = Nomina::computar(Nomina::rellenar($t->ficha(), $this->a), $d + ['recibo' => 'Nómina'], 0);
        $this->assertStringContainsString('base reguladora de los días de IT 1.400,00 €', $n['baseExpl'][0]);
    }

    public function test_it_sin_base_reguladora_es_un_error_que_impide_emitir(): void
    {
        $t = $this->trab();
        $this->aus($t, 'Incapacidad temporal', '2026-12-01', '2026-12-10', ['contingencia' => 'at']);
        $d = $this->doc($t, 12);
        $this->assertTrue(collect($d['avisosCal'])->contains(fn ($x) => $x[0] === 'error' && str_contains($x[1], 'falta la base reguladora')));
        $this->assertSame(0, collect($d['lineas'])->where('cod', '40')->count());
        $this->en()->post(route('laboral.nomina.guardar', $t->id), ['anio' => 2026, 'mes' => 12, 'recibo' => 'Nómina', 'accion' => 'emitir', 'l' => []])->assertSessionHasErrors('ficha');
        $this->assertSame(0, NominaMes::where('estado', 'Emitida')->count());
        // Con la nómina anterior emitida, la base reguladora sale de ella (base CC / 30)
        NominaMes::create(['cliente_id' => $this->a->id, 'trabajador_id' => $t->id, 'anio' => 2026, 'mes' => 11, 'recibo' => 'Nómina', 'estado' => 'Emitida', 'emitida_at' => now(), 'datos' => ['calc' => ['base' => 1800.0]]]);
        $L = collect($this->doc($t, 12)['lineas'])->where('cod', '40')->values();
        $this->assertSame([10 * 45.0], $L->pluck('importe')->all());
        $this->assertStringContainsString('1.800,00 €) / 30 días', $L[0]['expl']);
    }

    public function test_horas_extra_complementarias_y_por_concepto(): void
    {
        $this->v->update(['reglas' => ['jornada' => '1800', 'hx' => ['tipo' => 'importe', 'valor' => 15.0, 'compensacion' => 'economica', 'art' => 'art. 22']]]);
        $this->v->conceptos()->create(['nombre' => 'Plus de nocturnidad', 'naturaleza' => 'salarial', 'calculo' => 'fijo', 'unidad' => 'hora', 'valor' => 2, 'cotiza' => 'si', 'tributa' => 'si', 'redondeo' => '2d']);
        $t = $this->trab();
        LaboralHora::create(['cliente_id' => $this->a->id, 'trabajador_id' => $t->id, 'fecha' => '2026-12-03', 'tipo' => 'extra', 'horas' => 2]);
        LaboralHora::create(['cliente_id' => $this->a->id, 'trabajador_id' => $t->id, 'fecha' => '2026-12-04', 'tipo' => 'extra', 'horas' => 1.5]);
        LaboralHora::create(['cliente_id' => $this->a->id, 'trabajador_id' => $t->id, 'fecha' => '2026-12-04', 'tipo' => 'concepto', 'horas' => 4, 'concepto' => 'Plus de nocturnidad']);
        LaboralHora::create(['cliente_id' => $this->a->id, 'trabajador_id' => $t->id, 'fecha' => '2026-12-05', 'tipo' => 'complementaria', 'horas' => 2]);
        $d = $this->doc($t, 12);
        $L = collect($d['lineas'])->keyBy('concepto');
        $this->assertSame([3.5, 15.0, 52.5, 'hx'], [$L['Horas extraordinarias']['cant'], $L['Horas extraordinarias']['precio'], $L['Horas extraordinarias']['importe'], $L['Horas extraordinarias']['clase']]);
        $this->assertSame(8.0, $L['Plus de nocturnidad']['importe']);
        // Complementarias a tiempo completo: error, no se pagan
        $this->assertFalse($L->has('Horas complementarias'));
        $this->assertTrue(collect($d['avisosCal'])->contains(fn ($x) => $x[0] === 'error' && str_contains($x[1], 'complementarias')));
        // A tiempo parcial: complementarias a la hora ordinaria; las extra, error
        $p = $this->trab(['nombre' => 'Luis Gil Ros', 'nif' => '00000000T', 'jor' => 50, 'horas' => '20', 'salBase' => 750, 'contrato' => Nomina::contratoCompleto('200')]);
        LaboralHora::create(['cliente_id' => $this->a->id, 'trabajador_id' => $p->id, 'fecha' => '2026-12-03', 'tipo' => 'complementaria', 'horas' => 3]);
        LaboralHora::create(['cliente_id' => $this->a->id, 'trabajador_id' => $p->id, 'fecha' => '2026-12-04', 'tipo' => 'extra', 'horas' => 1]);
        $d2 = $this->doc($p, 12);
        $hc = collect($d2['lineas'])->firstWhere('concepto', 'Horas complementarias');
        $wp = Nomina::paraPeriodo(Nomina::rellenar($p->ficha(), $this->a), 2026, 12);
        $this->assertEqualsWithDelta(round(Nomina::anual($wp) / 900 * 3, 2), $hc['importe'], 0.001);
        $this->assertTrue(collect($d2['avisosCal'])->contains(fn ($x) => $x[0] === 'error' && str_contains($x[1], 'art. 12.4.c ET')));
    }

    public function test_cambio_de_condiciones_con_fecha_de_efecto_no_reescribe_meses_anteriores(): void
    {
        $t = $this->trab();
        TrabajadorCondicion::create(['cliente_id' => $this->a->id, 'trabajador_id' => $t->id, 'efectos_desde' => '2026-01-01', 'datos' => ['salTipo' => 'Mensual', 'salBase' => 1500, 'jor' => 100]]);
        TrabajadorCondicion::create(['cliente_id' => $this->a->id, 'trabajador_id' => $t->id, 'efectos_desde' => '2026-12-01', 'datos' => ['salTipo' => 'Mensual', 'salBase' => 1800, 'jor' => 100]]);
        $t->update(['datos' => array_merge($t->datos, ['salBase' => 1800])]);
        Calendario::olvidar();
        $this->assertSame(1500.0, collect($this->doc($t, 11)['lineas'])->firstWhere('id', 'sb')['importe']);
        $this->assertSame(1800.0, collect($this->doc($t, 12)['lineas'])->firstWhere('id', 'sb')['importe']);
        // Un cambio a mitad de mes: dos tramos, cada uno con sus condiciones (mes de 30 días: 14 + 16)
        TrabajadorCondicion::create(['cliente_id' => $this->a->id, 'trabajador_id' => $t->id, 'efectos_desde' => '2026-10-15', 'datos' => ['salTipo' => 'Mensual', 'salBase' => 1600, 'jor' => 100]]);
        $d = $this->doc($t, 10);
        $sb = collect($d['lineas'])->where('id', 'sb')->values();
        $this->assertSame(['Salario base (14 días) (del 1 al 14)', 'Salario base (16 días) (del 15 al 31)'], $sb->pluck('concepto')->all());
        $this->assertSame([700.0, 853.33], $sb->pluck('importe')->all());
        $this->assertSame(2, $d['tramos']);
        $this->assertStringContainsString('2 tramos', $d['avisosCal'][0][1]);
    }

    public function test_propuesta_de_horas_desde_los_fichajes_con_revision(): void
    {
        $this->v->update(['reglas' => ['jornada' => '1800', 'hx' => ['tipo' => 'importe', 'valor' => 15.0, 'compensacion' => 'economica', 'art' => '']]]);
        $t = $this->trab();
        JornadaDia::create(['cliente_id' => $this->a->id, 'trabajador_id' => $t->id, 'fecha' => '2026-12-02', 'tramos' => [['in' => '08:00', 'out' => '14:00'], ['in' => '15:00', 'out' => '19:00']]]);
        JornadaDia::create(['cliente_id' => $this->a->id, 'trabajador_id' => $t->id, 'fecha' => '2026-12-03', 'tramos' => [['in' => '08:00', 'out' => '16:00']]]);
        $w = Nomina::paraPeriodo(Nomina::rellenar($t->ficha(), $this->a), 2026, 12);
        $ex = Calendario::excesoFichajes($w, Calendario::mes($w, 2026, 12));
        $this->assertSame([['fecha' => '2026-12-02', 'mins' => 600, 'teo' => 480, 'exceso' => 2.0]], $ex);
        // Nada va a la nómina sin revisarlo
        $this->assertFalse(collect($this->doc($t, 12)['lineas'])->contains('concepto', 'Horas extraordinarias'));
        $this->en()->get(route('laboral.integral', ['anio' => 2026, 'mes' => 12, 't' => $t->id, 'vista' => 'calendario']))->assertOk()
            ->assertSee('Propuesta desde el control horario')->assertSee('2,00 h de más');
        $this->en()->post(route('laboral.cal.fichajes', $t->id), ['anio' => 2026, 'mes' => 12, 'tipo' => 'extra', 'dias' => ['2026-12-02']])->assertSessionHasNoErrors();
        $this->assertSame(30.0, collect($this->doc($t, 12)['lineas'])->firstWhere('concepto', 'Horas extraordinarias')['importe']);
        $this->assertSame([], Calendario::excesoFichajes($w, Calendario::mes($w, 2026, 12)));
    }

    public function test_pantalla_y_bloqueo_con_la_nomina_emitida(): void
    {
        $t = $this->trab();
        $this->en()->post(route('laboral.cal.ausencia', $t->id), ['anio' => 2026, 'mes' => 12, 'tipo' => 'Incapacidad temporal', 'desde' => '2026-12-01', 'hasta' => '2026-12-02'])
            ->assertSessionHasErrors('contingencia');
        $this->en()->post(route('laboral.cal.ausencia', $t->id), ['anio' => 2026, 'mes' => 12, 'tipo' => 'Vacaciones', 'desde' => '2026-12-21', 'hasta' => '2026-12-23'])->assertSessionHasNoErrors();
        $this->en()->post(route('laboral.cal.ausencia', $t->id), ['anio' => 2026, 'mes' => 12, 'tipo' => 'Vacaciones', 'desde' => '2026-12-22', 'hasta' => '2026-12-24'])->assertSessionHasErrors('cal');
        $this->en()->get(route('laboral.integral', ['anio' => 2026, 'mes' => 12, 't' => $t->id, 'vista' => 'calendario']))->assertOk()
            ->assertSee('Calendario de diciembre de 2026')->assertSee('En la nómina')->assertSee('Festivo: Inmaculada Concepción', false);
        NominaMes::create(['cliente_id' => $this->a->id, 'trabajador_id' => $t->id, 'anio' => 2026, 'mes' => 12, 'recibo' => 'Nómina', 'estado' => 'Emitida', 'emitida_at' => now(), 'datos' => []]);
        $this->en()->post(route('laboral.cal.horas', $t->id), ['anio' => 2026, 'mes' => 12, 'fecha' => '2026-12-03', 'tipo' => 'extra', 'horas' => '2'])->assertSessionHasErrors('cal');
        $this->assertSame(0, LaboralHora::count());
        // Otra empresa no ve ni toca este calendario
        $b = $this->cliente('Otra SL', 'B21000000');
        $this->actingAs($this->as)->withSession(['cliente_activo' => $b->id])->post(route('laboral.cal.horas', $t->id), ['anio' => 2026, 'mes' => 11, 'fecha' => '2026-11-03', 'tipo' => 'extra', 'horas' => '2'])->assertNotFound();
    }
}
