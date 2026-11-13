<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Convenio;
use App\Models\ConvenioCategoria;
use App\Models\ConvenioConcepto;
use App\Models\ConvenioVersion;
use App\Models\EmpresaConvenio;
use App\Models\User;
use App\Services\Convenios;
use App\Services\Nomina;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Fase 3 laboral: editor manual del convenio por secciones, validado en el servidor y conectado con la nómina */
class ConvenioEditorTest extends TestCase
{
    use RefreshDatabase;

    private Cliente $a;

    private User $as;

    private ConvenioVersion $v;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-13 10:00:00');
        CarbonImmutable::setTestNow('2026-11-13 10:00:00');
        $this->a = $this->cliente('Construcciones Uno SL', 'B21890124');
        $this->as = $this->usuario(User::ASESOR);
        $cv = Convenio::create(['nombre' => 'Construcción de Huelva', 'estado' => 'borrador']);
        EmpresaConvenio::create(['cliente_id' => $this->a->id, 'convenio_id' => $cv->id]);
        $this->v = ConvenioVersion::create(['convenio_id' => $cv->id, 'nombre' => '2026', 'estado' => 'borrador', 'reglas' => []]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function sec(string $s, array $d)
    {
        return $this->actingAs($this->as)->withSession(['cliente_activo' => $this->a->id])->post(route('laboral.convenios.seccion', $s), $d + ['version_id' => $this->v->id]);
    }

    private function cats(): void
    {
        $this->sec('categorias', ['cats' => [
            ['nombre' => 'Oficial de primera', 'codigo' => 'OF1', 'grupo_profesional' => 'Grupo V', 'nivel' => 'VIII', 'grupo_cotizacion' => '8', 'unidad' => 'mes', 'salario_base' => '1.600,00', 'plus' => '120,00', 'referencia' => 'Anexo I'],
            ['nombre' => 'Peón', 'grupo_profesional' => 'Grupo II', 'nivel' => 'XII', 'grupo_cotizacion' => '10', 'unidad' => 'dia', 'salario_base' => '45,50', 'plus' => '0'],
        ]])->assertSessionHasNoErrors();
    }

    public function test_identificacion_y_version_con_errores_junto_al_campo(): void
    {
        $this->sec('identificacion', ['nombre' => '', 'estado' => 'aprobado', 'url' => 'no es web'])->assertSessionHasErrors(['nombre', 'url']);
        $this->sec('identificacion', ['nombre' => 'Construcción de Huelva', 'codigo' => '21000085011981', 'estado' => 'aprobado', 'publicacion' => 'BOP Huelva nº 50', 'url' => 'https://www.diphuelva.es/bop',
            'fecha_publicacion' => '2026-03-12', 'ambito_funcional' => 'Empresas de construcción'])->assertSessionHasNoErrors()->assertSessionHas('ok');
        $cv = Convenio::firstOrFail();
        $this->assertSame(['21000085011981', 'BOP Huelva nº 50', '2026-03-12'], [$cv->codigo, $cv->publicacion, $cv->fecha_publicacion->toDateString()]);

        $this->sec('version', ['nombre' => '2026', 'efectos_desde' => '2026-06-01', 'efectos_hasta' => '2026-01-01', 'estado' => 'borrador'])->assertSessionHasErrors('efectos_hasta');
        // No se aprueba una versión sin categorías con salario
        $this->sec('version', ['nombre' => '2026', 'efectos_desde' => '2026-01-01', 'estado' => 'aprobado'])->assertSessionHasErrors('estado');
        $this->cats();
        $this->sec('version', ['nombre' => '2026', 'efectos_desde' => '2026-01-01', 'efectos_hasta' => '2026-12-31', 'estado' => 'aprobado'])->assertSessionHasNoErrors();
        $v = $this->v->fresh();
        $this->assertSame(['aprobado', $this->as->id], [$v->estado, $v->aprobada_por]);
        // El error se pinta junto al campo y se conservan los datos escritos
        $this->actingAs($this->as)->withSession(['cliente_activo' => $this->a->id])->followingRedirects()
            ->post(route('laboral.convenios.seccion', 'identificacion'), ['version_id' => $this->v->id, 'nombre' => '', 'estado' => 'aprobado', 'sector' => 'Construcción y obras'])
            ->assertSee('El convenio necesita un nombre.')->assertSee('value="Construcción y obras"', false)->assertSee('aria-invalid=true', false);
    }

    public function test_categorias_sin_duplicados_y_salario_no_localizado(): void
    {
        $this->sec('categorias', ['cats' => [['nombre' => 'Peón', 'unidad' => 'dia'], ['nombre' => 'Peón', 'unidad' => 'dia']]])->assertSessionHasErrors('cats.1.nombre');
        $this->sec('categorias', ['cats' => [['nombre' => 'Peón', 'unidad' => 'dia', 'grupo_cotizacion' => '14']]])->assertSessionHasErrors('cats.0.grupo_cotizacion');
        $this->cats();
        $this->cats();
        $c = ConvenioCategoria::orderBy('orden')->get();
        $this->assertSame(['Oficial de primera', 'Peón'], $c->pluck('nombre')->all());
        $this->assertSame(['OF1', 'Grupo V', 'VIII', 8, 'Anexo I'], [$c[0]->codigo, $c[0]->grupo_profesional, $c[0]->nivel, $c[0]->grupo_cotizacion, $c[0]->referencia]);
        $this->assertSame(120.0, Convenios::forma(Convenio::first(), $this->v)['cats'][0]['plus']);
        $this->sec('categorias', ['cats' => [['nombre' => 'Encargado', 'unidad' => 'mes', 'salario_base' => '']]])->assertSessionHasNoErrors();
        $this->assertNull(ConvenioCategoria::where('nombre', 'Encargado')->first()->salario_base);
        $this->assertSame(1, ConvenioCategoria::count());
    }

    public function test_conceptos_completos_con_importe_por_categoria_y_validaciones(): void
    {
        $this->cats();
        $ids = ConvenioCategoria::pluck('id', 'nombre');
        $base = ['naturaleza' => 'salarial', 'calculo' => 'fijo', 'unidad' => 'mes', 'cotiza' => 'si', 'tributa' => 'si', 'redondeo' => '2d'];
        // Sin importe general ni por categoría; porcentaje sin base; límite sin describir; nombre reservado
        $this->sec('conceptos', ['conceptos' => [
            $base + ['nombre' => 'Plus de asistencia'],
            array_merge($base, ['nombre' => 'Plus de peligrosidad', 'calculo' => 'porcentaje', 'valor' => '10']),
            array_merge($base, ['nombre' => 'Dietas', 'naturaleza' => 'extrasalarial', 'valor' => '30', 'cotiza' => 'limite']),
            $base + ['nombre' => Convenios::PLUS_CONVENIO, 'valor' => '1'],
        ]])->assertSessionHasErrors(['conceptos.0.valor', 'conceptos.1.base', 'conceptos.2.limite', 'conceptos.3.nombre']);
        $this->assertSame(0, ConvenioConcepto::where('nombre', '!=', Convenios::PLUS_CONVENIO)->count());

        $this->sec('conceptos', ['conceptos' => [
            array_merge($base, ['nombre' => 'Plus de transporte', 'unidad' => 'dia_trabajado', 'valor' => '4,00', 'automatico' => '1', 'prop_jornada' => '1', 'prop_periodo' => '1', 'cra' => '0010',
                'importes' => [$ids['Peón'] => '5,00']]),
            array_merge($base, ['nombre' => 'Plus de nocturnidad', 'unidad' => 'hora', 'valor' => '1,50']),
            array_merge($base, ['nombre' => 'Plus de festivos', 'calculo' => 'formula', 'formula' => 'salario_base / 30 * 1,75', 'automatico' => '1']),
            array_merge($base, ['nombre' => 'Dietas', 'naturaleza' => 'extrasalarial', 'valor' => '30', 'cotiza' => 'limite', 'tributa' => 'limite', 'limite' => '26,67 €/día en España']),
        ]])->assertSessionHasNoErrors();
        $t = ConvenioConcepto::where('nombre', 'Plus de transporte')->first();
        $this->assertSame(['0010', 'dia_trabajado', true, '5.0000'], [$t->cra, $t->unidad, $t->automatico, $t->importes->first()->importe]);
        $this->assertFalse(Convenios::soportado(ConvenioConcepto::where('nombre', 'Plus de festivos')->first()));
        $this->assertFalse(Convenios::soportado(ConvenioConcepto::where('nombre', 'Dietas')->first()));

        // En la nómina: transporte con el importe del Peón (5 € × días laborables); la fórmula no se aproxima
        $this->v->update(['estado' => 'aprobado', 'efectos_desde' => '2026-01-01']);
        $w = Nomina::rellenar(['id' => 1, 'contrato' => Nomina::CONTRATOS[0], 'grupo' => 10, 'salTipo' => 'Diario', 'salDia' => 45.5, 'pagas' => 14, 'jor' => 100, 'alta' => '2026-01-01', 'convCat' => 'Peón'], $this->a);
        $L = collect(Nomina::conceptos($w, 11, [], null, 2026)['lineas'])->keyBy('concepto');
        $this->assertEqualsWithDelta(Nomina::diasLaborables(2026, 11) * 5.0, $L['Plus de transporte']['importe'], 0.001);
        $this->assertFalse($L->has('Plus de festivos'));
        $this->assertFalse($L->has('Dietas'));
        // A media jornada, proporcional
        $L2 = collect(Nomina::conceptos(array_merge($w, ['jor' => 50]), 11, [], null, 2026)['lineas'])->keyBy('concepto');
        $this->assertEqualsWithDelta(Nomina::diasLaborables(2026, 11) * 2.5, $L2['Plus de transporte']['importe'], 0.001);
        $this->actingAs($this->as)->withSession(['cliente_activo' => $this->a->id])->get(route('laboral.convenios'))
            ->assertSee('El motor todavía no calcula este concepto')->assertSee('value="26,67 €/día en España"', false);
    }

    public function test_pagas_antiguedad_jornada_it_y_contratacion(): void
    {
        $this->cats();
        $this->sec('pagas', ['extras' => [['nombre' => 'Verano', 'mes' => '6', 'calculo' => 'dias', 'dias' => '', 'devengo_tipo' => 'otro']]])
            ->assertSessionHasErrors(['extras.0.dias', 'extras.0.devengo.desde', 'extras.0.devengo.hasta']);
        $pagas = [];
        foreach ([['Verano', 6], ['Navidad', 12], ['Beneficios', 3], ['Vacaciones', 8]] as [$nom, $m]) {
            $pagas[] = ['nombre' => $nom, 'mes' => $m, 'calculo' => 'dias', 'dias' => '30', 'devengo_tipo' => 'semestral', 'conceptos' => 'Salario base y antigüedad', 'referencia' => 'Art. 50'];
        }
        $this->sec('pagas', ['extras' => $pagas])->assertSessionHasNoErrors();
        $r = $this->v->fresh()->reglas;
        $this->assertSame(['16', 4, 'semestral'], [$r['pagas'], count($r['extras']), $r['extras'][0]['devengo']['tipo']]);

        $this->sec('antiguedad', ['si' => '1', 'periodo' => '3', 'tipo' => 'porcentaje', 'valor' => ''])->assertSessionHasErrors('valor');
        $this->sec('antiguedad', ['si' => '1', 'periodo' => '3', 'tipo' => 'porcentaje', 'valor' => '5', 'base' => 'salario_base_plus', 'tope' => '', 'computo' => 'alta', 'congelada' => '2024-12-31'])->assertSessionHasNoErrors();
        $this->v->update(['estado' => 'aprobado', 'efectos_desde' => '2026-01-01']);
        // Alta en 2015: a 31/12/2024 lleva 9 años = 3 trienios (congelada), 5 % de (1.600 + 120) × 3 = 258
        $w = Nomina::rellenar(['id' => 1, 'contrato' => Nomina::CONTRATOS[0], 'grupo' => 8, 'salBase' => 1600, 'plus' => 120, 'pagas' => 14, 'jor' => 100, 'alta' => '2015-06-01', 'convCat' => 'Oficial de primera'], $this->a);
        $w = Nomina::paraPeriodo($w, 2026, 11);
        $this->assertSame([258.0, 3], array_slice(Nomina::antiguedad($w, 2026, 11), 0, 2));

        $this->sec('jornada', ['jornada' => '1.736', 'vacaciones' => '30', 'vac_tipo' => 'naturales', 'hx_tipo' => 'importe', 'hx_valor' => ''])->assertSessionHasErrors('hx_valor');
        $this->sec('jornada', ['jornada' => '1.736', 'semanal' => '40', 'vacaciones' => '22', 'vac_tipo' => 'laborables', 'hx_tipo' => 'recargo', 'hx_valor' => '75', 'hx_compensacion' => 'ambas',
            'distribucion' => 'Lunes a viernes', 'nocturno' => '25 % entre las 22 y las 6'])->assertSessionHasNoErrors();
        $r = $this->v->fresh()->reglas;
        $this->assertSame(['1736', '22', 'laborables', 'recargo', 'ambas'], [$r['jornada'], $r['vacaciones'], $r['calendario']['vac_tipo'], $r['hx']['tipo'], $r['hx']['compensacion']]);

        $this->sec('it', ['it_cc' => [['desde' => '10', 'hasta' => '5', 'pct' => '75']]])->assertSessionHasErrors('it_cc.0.hasta');
        $this->sec('it', ['it_cc' => [['desde' => '1', 'hasta' => '20', 'pct' => '75'], ['desde' => '21', 'hasta' => '', 'pct' => '100', 'hosp' => '1']], 'it_at' => [['desde' => '1', 'pct' => '100']],
            'base' => 'Salario real del mes anterior'])->assertSessionHasNoErrors();
        $it = $this->v->fresh()->reglas['it'];
        $this->assertSame([2, true, 100.0, 'Salario real del mes anterior'], [count($it['cc']), $it['cc'][1]['hosp'], (float) $it['at'][0]['pct'], $it['base']]);

        $this->sec('contratacion', ['prueba' => [['aplica' => 'Grupos I y II', 'modalidad' => 'Indefinido', 'duracion' => '6 meses'], ['aplica' => '', 'modalidad' => '', 'duracion' => '']],
            'preaviso' => [['quien' => 'Trabajador', 'aplica' => 'Todos', 'plazo' => '15 días']], 'clausulas' => 'Subrogación del art. 27'])->assertSessionHasNoErrors();
        $ct = $this->v->fresh()->reglas['contrato'];
        $this->assertSame([1, '15 días', 'Subrogación del art. 27'], [count($ct['prueba']), $ct['preaviso'][0]['plazo'], $ct['clausulas']]);
        // Estado por sección en la navegación
        $this->actingAs($this->as)->withSession(['cliente_activo' => $this->a->id])->get(route('laboral.convenios'))->assertOk()
            ->assertSee('Contratación y extinción')->assertSee('la nómina todavía no calcula las bajas', false)->assertSee('Cambios sin guardar');
    }

    public function test_otra_empresa_no_puede_editar_un_convenio_ajeno_ni_el_portal(): void
    {
        $portal = $this->usuario(User::CLIENTE_ADMIN, $this->a);
        $this->assertContains($this->actingAs($portal)->post(route('laboral.convenios.seccion', 'identificacion'), ['nombre' => 'X', 'estado' => 'aprobado'])->status(), [302, 403, 404]);
        $this->assertSame('Construcción de Huelva', Convenio::withoutGlobalScopes()->first()->nombre);
        // Una versión de otro convenio no se puede editar desde esta empresa: se usa la de su convenio
        $otro = Convenio::create(['nombre' => 'Otro', 'estado' => 'borrador']);
        $vo = ConvenioVersion::create(['convenio_id' => $otro->id, 'nombre' => 'Ajena', 'estado' => 'borrador', 'reglas' => []]);
        $this->actingAs($this->as)->withSession(['cliente_activo' => $this->a->id])->post(route('laboral.convenios.seccion', 'version'), ['version_id' => $vo->id, 'nombre' => 'Cambiada', 'estado' => 'borrador']);
        $this->assertSame('Ajena', $vo->fresh()->nombre);
        $this->actingAs($this->as)->withSession(['cliente_activo' => $this->a->id])->post(route('laboral.convenios.seccion', 'inventada'), [])->assertNotFound();
    }
}
