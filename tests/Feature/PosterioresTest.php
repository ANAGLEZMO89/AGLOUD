<?php

namespace Tests\Feature;

use App\Models\Apunte;
use App\Models\Asiento;
use App\Models\Cliente;
use App\Models\Convenio;
use App\Models\ConvenioVersion;
use App\Models\EmpresaConvenio;
use App\Models\JornadaAusencia;
use App\Models\LaboralMes;
use App\Models\NominaMes;
use App\Models\Trabajador;
use App\Models\User;
use App\Services\Nomina;
use App\Services\Posteriores;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Fase 8 laboral: seguros sociales, CRA, asiento y modelos a partir de lo emitido, coherentes con rectificaciones y atrasos */
class PosterioresTest extends TestCase
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
            'pagas' => 14, 'prorr' => true, 'jor' => 100, 'horas' => '40', 'alta' => '2026-01-01', 'iban' => 'ES9121000418450200051332'], $d);

        return Trabajador::create(['cliente_id' => $this->a->id, 'estado' => 'Alta', 'alta' => $d['alta'], 'contrato' => '100', 'datos' => $d]);
    }

    public function test_asiento_solo_de_lo_emitido_con_it_delegada_especie_e_indemnizacion(): void
    {
        $t = $this->trab(['dedFijas' => [['concepto' => 'Cuota sindical', 'importe' => 10]],
            'concFijos' => [['cod' => '51', 'concepto' => 'Seguro médico pagado por la empresa', 'clase' => 'especie', 'importe' => 40]]]);
        // IT común del 1 al 30 de noviembre con base reguladora de 50 €/día
        JornadaAusencia::create(['cliente_id' => $this->a->id, 'trabajador_id' => $t->id, 'tipo' => 'Incapacidad temporal', 'contingencia' => 'cc', 'base_reguladora' => 50,
            'desde' => '2026-11-01', 'hasta' => '2026-11-30', 'estado' => 'confirmada', 'origen' => 'nomina']);
        $this->en()->post(route('laboral.contabilizar'), ['anio' => 2026, 'mes' => 11])->assertSessionHasErrors('ficha');
        $this->en()->post(route('laboral.emitir.mes'), ['anio' => 2026, 'mes' => 11])->assertSessionHasNoErrors();
        $g = NominaMes::firstOrFail();
        $calc = $g->datos['calc'];
        // Pago delegado: días 16 a 20 al 60 % (5 × 30) y desde el 21 al 75 % (10 × 37,5)
        $this->assertSame(150.0 + 375.0, (float) $calc['deleg']);
        $this->en()->post(route('laboral.contabilizar'), ['anio' => 2026, 'mes' => 11])->assertSessionHasNoErrors();
        $a = Asiento::where('origen', 'laboral')->firstOrFail();
        $ap = Apunte::where('asiento_id', $a->id)->get()->groupBy('cuenta')->map(fn ($g) => [round((float) $g->sum('debe'), 2), round((float) $g->sum('haber'), 2)]);
        $this->assertSame([525.0, 0.0], $ap['471000000']);
        $this->assertSame([0.0, 40.0], $ap['755000000']);
        $this->assertSame([0.0, 10.0], $ap['555000000']);
        $this->assertSame([round((float) $calc['liq'], 2)], [$ap['465000000'][1]]);
        // 640: sueldos (incluida la especie, que va a la misma cuenta salvo que se configure otra) sin la IT delegada
        $this->assertSame(round((float) $calc['dev'] - 525, 2), $ap['640000000'][0]);
        $this->assertFalse($ap->has('460000000'));
        $this->assertEqualsWithDelta($ap->sum(fn ($x) => $x[0]), $ap->sum(fn ($x) => $x[1]), 0.001);
        // Ni dos asientos ni rectificar sin borrar el asiento
        $this->en()->post(route('laboral.contabilizar'), ['anio' => 2026, 'mes' => 11])->assertSessionHasErrors('ficha');
        $this->assertSame(1, Asiento::where('origen', 'laboral')->count());
        $this->en()->post(route('laboral.nomina.guardar', $t->id), ['anio' => 2026, 'mes' => 11, 'accion' => 'reabrir', 'motivo' => 'Error en la IT'])->assertSessionHasErrors('ficha');
    }

    public function test_seguros_sociales_con_finiquito_atrasos_aparte_y_regularizacion(): void
    {
        $t = $this->trab();
        $this->en()->post(route('laboral.emitir.mes'), ['anio' => 2026, 'mes' => 10])->assertSessionHasNoErrors();
        // Revisión salarial retroactiva y recibo de atrasos en diciembre
        $t->update(['datos' => array_merge($t->datos, ['salBase' => 1600])]);
        $this->en()->post(route('laboral.emitir.mes'), ['anio' => 2026, 'mes' => 12])->assertSessionHasNoErrors();
        $atr = Nomina::conceptosRecibo(Nomina::rellenar($t->fresh()->ficha(), $this->a), 'Atrasos', 2026, 12);
        // 100 € de salario + 2 × 8,33 € de prorrata de pagas
        $this->assertSame(116.66, collect($atr['lineas'])->firstWhere('id', 'atr10')['importe']);
        NominaMes::create(['cliente_id' => $this->a->id, 'trabajador_id' => $t->id, 'anio' => 2026, 'mes' => 12, 'recibo' => 'Atrasos', 'estado' => 'Emitida', 'emitida_at' => now(),
            'datos' => $atr + ['calc' => Nomina::computar(Nomina::rellenar($t->fresh()->ficha(), $this->a), $atr + ['recibo' => 'Atrasos'], 0)]]);
        Nomina::olvidarGuardadas();
        $noms = app(Nomina::class)->mes($this->a, 2026, 12);
        $ss = Posteriores::seguros($noms);
        // Los atrasos no suben la base de diciembre: van a la complementaria de octubre
        $nom = collect($noms)->first()['n'];
        $this->assertSame(round((float) $nom['base'], 2), round($ss['filas'][0]['base'], 2));
        $this->assertSame([10], array_keys($ss['atrasos']));
        $this->en()->get(route('laboral.seguros', ['anio' => 2026, 'mes' => 12]))->assertOk()->assertSee('liquidaciones complementarias')->assertSee('Octubre:', false)
            ->assertSee('Anotar que está confirmada en el SLD')->assertSee('Presentar');
        // Anotada como confirmada; después se rectifica una nómina: aviso de regularización
        LaboralMes::updateOrCreate(['cliente_id' => $this->a->id, 'anio' => 2026, 'mes' => 12], ['ss_confirmada_at' => now()->subHour()]);
        $this->en()->post(route('laboral.nomina.guardar', $t->id), ['anio' => 2026, 'mes' => 12, 'accion' => 'reabrir', 'motivo' => 'Corrección del plus'])->assertSessionHasNoErrors();
        $this->en()->get(route('laboral.seguros', ['anio' => 2026, 'mes' => 12]))->assertSee('Después de confirmar la liquidación')->assertSee('Ana Pérez Gil (Nómina)');
    }

    public function test_cra_con_el_codigo_del_concepto_del_convenio(): void
    {
        $this->v->conceptos()->create(['nombre' => 'Plus de transporte', 'cra' => '0012', 'naturaleza' => 'salarial', 'calculo' => 'fijo', 'unidad' => 'mes', 'valor' => 60, 'automatico' => true,
            'cotiza' => 'si', 'tributa' => 'si', 'redondeo' => '2d', 'prop_jornada' => true, 'prop_periodo' => true]);
        $this->trab();
        $this->en()->post(route('laboral.cra.guardar'), ['cra' => ['sb' => '0001', 'pror' => '0002']]);
        $filas = collect(Posteriores::cra(app(Nomina::class)->mes($this->a, 2026, 11), Nomina::config($this->a)));
        $this->assertSame(['0001', '0012', '0002'], $filas->pluck('cod')->all());
        $this->assertTrue($filas->firstWhere('cod', '0012')['propio'] && $filas->firstWhere('cod', '0012')['provisional']);
        $this->en()->get(route('laboral.cra', ['anio' => 2026, 'mes' => 11]))->assertOk()->assertSee('código del convenio')->assertSee('Datos provisionales');
    }

    public function test_modelos_111_y_190_avisan_de_lo_no_emitido(): void
    {
        $this->trab();
        $this->en()->get(route('laboral.m111', ['anio' => 2026, 't' => 4]))->assertOk()->assertSee('Provisional')->assertSee('sede electrónica de la AEAT');
        $this->en()->post(route('laboral.emitir.mes'), ['anio' => 2026, 'mes' => 10]);
        $this->en()->post(route('laboral.emitir.mes'), ['anio' => 2026, 'mes' => 11]);
        $this->en()->post(route('laboral.emitir.mes'), ['anio' => 2026, 'mes' => 12]);
        $this->en()->get(route('laboral.m111', ['anio' => 2026, 't' => 4]))->assertOk()->assertDontSee('<b>Provisional:</b>', false);
    }
}
