<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Convenio;
use App\Models\ConvenioCategoria;
use App\Models\ConvenioConcepto;
use App\Models\ConvenioDocumento;
use App\Models\ConvenioVersion;
use App\Models\EmpresaConvenio;
use App\Models\LaboralConfig;
use App\Models\NominaMes;
use App\Models\Trabajador;
use App\Models\User;
use App\Services\Convenios;
use App\Services\Nomina;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Fase 2 laboral: convenios del despacho con versiones, tablas por categoría, conceptos y asignación a empresas */
class ConveniosVersionesTest extends TestCase
{
    use RefreshDatabase;

    private Cliente $a;

    private User $as;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-13 10:00:00');
        CarbonImmutable::setTestNow('2026-11-13 10:00:00');
        $this->a = $this->cliente('Construcciones Uno SL', 'B21890124');
        $this->as = $this->usuario(User::ASESOR);
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

    /** Convenio con dos versiones aprobadas: primer semestre (1.500 €) y segundo (1.600 € + paga de beneficios en julio) */
    private function dosVersiones(): array
    {
        $cv = Convenio::create(['nombre' => 'Construcción de Huelva', 'codigo' => '21000085011981', 'estado' => 'aprobado']);
        EmpresaConvenio::create(['cliente_id' => $this->a->id, 'convenio_id' => $cv->id]);
        $v1 = ConvenioVersion::create(['convenio_id' => $cv->id, 'nombre' => '2026 S1', 'efectos_desde' => '2026-01-01', 'efectos_hasta' => '2026-06-30', 'estado' => 'aprobado', 'reglas' => ['pagas' => '14']]);
        Convenios::guardar($v1, ['cats' => [['cat' => 'Oficial', 'grupo' => '8', 'base' => 1500, 'plus' => 100, 'unidad' => 'mes']], 'pluses' => []]);
        $v2 = Convenios::nuevaVersion($v1, ['nombre' => '2026 S2', 'efectos_desde' => '2026-07-01']);
        $v2->update(['estado' => 'aprobado']);
        Convenios::guardar($v2, ['cats' => [['cat' => 'Oficial', 'grupo' => '8', 'base' => 1600, 'plus' => 100, 'unidad' => 'mes']],
            'extras' => [['nombre' => 'Paga de verano', 'mes' => 6, 'calculo' => 'mensualidad', 'dias' => null, 'importe' => null, 'plus' => false, 'antig' => false],
                ['nombre' => 'Paga de Navidad', 'mes' => 12, 'calculo' => 'mensualidad', 'dias' => null, 'importe' => null, 'plus' => false, 'antig' => false],
                ['nombre' => 'Paga de beneficios', 'mes' => 7, 'calculo' => 'importe', 'dias' => null, 'importe' => 300, 'plus' => false, 'antig' => false]]]);

        return [$cv, $v1, $v2];
    }

    public function test_la_nomina_usa_la_version_de_su_mes(): void
    {
        [, $v1, $v2] = $this->dosVersiones();
        $this->assertSame(['Paga de verano', 'Paga de Navidad', 'Paga de beneficios'], array_column(Nomina::config($this->a, '2026-08-01')['conv']['extras'], 'nombre'));
        $this->assertSame([], Nomina::config($this->a, '2026-03-01')['conv']['extras']);
        $w = Nomina::rellenar(['id' => 1, 'contrato' => Nomina::CONTRATOS[0], 'grupo' => 8, 'salBase' => 1500, 'pagas' => 14, 'jor' => 100, 'alta' => '2026-01-01', 'convCat' => 'Oficial'], $this->a);
        $sb = fn ($m) => collect(Nomina::conceptos($w, $m, [], null, 2026)['lineas'])->firstWhere('id', 'sb');
        // La referencia del convenio cambia con la versión: junio 1.500, julio 1.600 (y avisa de que el salario no llega)
        $this->assertSame(1500.0, (float) $sb(6)['conv']);
        $this->assertSame(1600.0, (float) $sb(7)['conv']);
        $this->assertSame($v2->id, Nomina::conceptos($w, 7, [], null, 2026)['convenio']['version_id']);
        $this->assertSame($v1->id, Nomina::conceptos($w, 3, [], null, 2026)['convenio']['version_id']);
        // La paga de beneficios solo existe en la versión del segundo semestre
        $lineas7 = collect(Nomina::conceptos($w, 7, [], null, 2026)['lineas'])->pluck('concepto')->all();
        $this->assertContains('Paga de beneficios', $lineas7);
        $this->assertNotContains('Paga de beneficios', collect(Nomina::conceptos($w, 3, [], null, 2026)['lineas'])->pluck('concepto')->all());
    }

    public function test_aprobada_prevalece_y_borrador_solo_si_no_hay_aprobada(): void
    {
        [$cv, $v1] = $this->dosVersiones();
        $b = ConvenioVersion::create(['convenio_id' => $cv->id, 'nombre' => 'Borrador marzo', 'efectos_desde' => '2026-03-01', 'efectos_hasta' => '2026-03-31', 'estado' => 'borrador', 'reglas' => []]);
        $this->assertSame($v1->id, Convenios::version($cv, '2026-03-15')->id);
        $v1->update(['estado' => 'sustituido']);
        $this->assertSame($b->id, Convenios::version($cv, '2026-03-15')->id);
        $this->assertSame('borrador', Nomina::config($this->a, '2026-03-15')['conv']['estadoVersion']);
    }

    public function test_validaciones_de_versiones_y_tablas(): void
    {
        [$cv, $v1, $v2] = $this->dosVersiones();
        $this->assertSame([], array_filter(Convenios::validar($cv), fn ($x) => $x[0] === 'error'));
        // Solapamiento
        $v1->update(['efectos_hasta' => '2026-07-15']);
        $this->assertStringContainsString('Se solapan', implode(' ', array_column(Convenios::validar($cv), 1)));
        // Hueco
        $v1->update(['efectos_hasta' => '2026-05-31']);
        $this->assertStringContainsString('Hueco', implode(' ', array_column(Convenios::validar($cv), 1)));
        // Categoría sin salario: no localizado (null), nunca cero
        Convenios::guardar($v2, ['cats' => [['cat' => 'Oficial', 'grupo' => '8', 'base' => null, 'plus' => 0, 'unidad' => 'mes']]]);
        $this->assertNull(ConvenioCategoria::where('version_id', $v2->id)->first()->salario_base);
        $this->assertStringContainsString('falta el salario base', implode(' ', array_column(Convenios::validar($cv), 1)));
    }

    public function test_nueva_version_copia_tablas_conceptos_e_importes_y_cierra_la_anterior(): void
    {
        [$cv, , $v2] = $this->dosVersiones();
        Convenios::guardar($v2, ['pluses' => [['nombre' => 'Plus de transporte', 'unidad' => 'dia_trabajado', 'tipo' => 'importe', 'valor' => 4, 'auto' => true, 'cotiza' => true, 'tributa' => true]]]);
        $this->en()->post(route('laboral.convenios.version'), ['base_id' => $v2->id, 'nombre' => '2027', 'efectos_desde' => '2027-01-01', 'cerrar' => '1'])->assertRedirect()->assertSessionHas('ok');
        $n = ConvenioVersion::where('nombre', '2027')->firstOrFail();
        $this->assertSame('borrador', $n->estado);
        $this->assertSame('2026-12-31', $v2->fresh()->efectos_hasta->toDateString());
        $this->assertSame(['Oficial'], ConvenioCategoria::where('version_id', $n->id)->pluck('nombre')->all());
        $this->assertEqualsCanonicalizing([Convenios::PLUS_CONVENIO, 'Plus de transporte'], ConvenioConcepto::where('version_id', $n->id)->pluck('nombre')->all());
        $this->assertSame(100.0, Convenios::forma($cv, $n)['cats'][0]['plus']);
    }

    public function test_formulario_guarda_en_la_version_elegida_sin_duplicar(): void
    {
        [$cv, $v1] = $this->dosVersiones();
        $datos = ['_enviado' => 1, 'version_id' => $v1->id, 'conv_nombre' => 'Construcción de Huelva', 'ver_nombre' => '2026 S1', 'ver_efectos_desde' => '2026-01-01', 'ver_efectos_hasta' => '2026-06-30', 'ver_estado' => 'aprobado',
            'conv_estado' => 'aprobado', 'conv_publicacion' => 'BOP de Huelva nº 50', 'conv_no_soportado' => 'Plus de festivos especiales del art. 30',
            'cats' => [['cat' => 'Oficial', 'grupo_profesional' => 'Grupo IV', 'nivel' => 'VIII', 'grupo' => '8', 'unidad' => 'mes', 'base' => '1.520,00', 'plus' => '100,00'],
                ['cat' => 'Peón', 'grupo_profesional' => 'Grupo II', 'nivel' => 'XII', 'grupo' => '10', 'unidad' => 'dia', 'base' => '45,50', 'plus' => '0']]];
        $this->en()->post(route('laboral.convenios.guardar'), $datos)->assertRedirect(route('laboral.convenios', ['version' => $v1->id]));
        $this->en()->post(route('laboral.convenios.guardar'), $datos);
        $cats = ConvenioCategoria::where('version_id', $v1->id)->orderBy('orden')->get();
        $this->assertSame(['Oficial', 'Peón'], $cats->pluck('nombre')->all());
        $this->assertSame(['Grupo IV', 'VIII', 8, '1520.0000'], [$cats[0]->grupo_profesional, $cats[0]->nivel, $cats[0]->grupo_cotizacion, $cats[0]->salario_base]);
        $this->assertSame('dia', $cats[1]->unidad);
        $this->assertSame('BOP de Huelva nº 50', $cv->fresh()->publicacion);
        $this->assertSame('Plus de festivos especiales del art. 30', $cv->fresh()->no_soportado);
        // Fechas incoherentes: no se guarda
        $this->en()->post(route('laboral.convenios.guardar'), array_merge($datos, ['ver_efectos_hasta' => '2025-01-01']))->assertSessionHasErrors('conv');
        $this->en()->get(route('laboral.convenios', ['version' => $v1->id]))->assertOk()->assertSee('Versión y vigencia')->assertSee('2026 S2')->assertSee('value="Grupo IV"', false);
    }

    public function test_asignar_convenio_compartido_conserva_el_anterior_para_meses_pasados(): void
    {
        [$cv] = $this->dosVersiones();
        $b = $this->cliente('Reformas Dos SL', 'B21112230');
        $this->actingAs($this->as)->withSession(['cliente_activo' => $b->id])->post(route('laboral.convenios.asignar'), ['convenio_id' => $cv->id])->assertSessionHas('ok');
        $this->assertSame($cv->id, Convenios::asignacion($b)->convenio_id);
        // La primera empresa cambia a un convenio nuevo desde noviembre: octubre sigue con el anterior
        $this->en()->post(route('laboral.convenios.asignar'), ['convenio_id' => 'nuevo', 'nombre' => 'Metal de Huelva'])->assertSessionHas('ok');
        $this->assertSame('Metal de Huelva', Convenios::asignacion($this->a, '2026-11-20')->convenio->nombre);
        $this->assertSame('Construcción de Huelva', Convenios::asignacion($this->a, '2026-10-15')->convenio->nombre);
        $this->actingAs($this->as)->withSession(['cliente_activo' => $b->id])->get(route('laboral.convenios'))->assertSee('lo usan 2 empresas');
    }

    public function test_documento_del_convenio_y_aislamiento(): void
    {
        $desp = $this->cliente('GA AGOPYME SL', 'B21000000');
        $desp->es_despacho = true;
        $desp->save();
        [$cv] = $this->dosVersiones();
        $pdf = UploadedFile::fake()->createWithContent('convenio.pdf', "%PDF-1.4\nconvenio");
        $this->en()->post(route('laboral.convenios.documento'), ['pdf' => $pdf, 'tipo' => 'tablas', 'descripcion' => 'Tablas 2026'])->assertSessionHas('ok');
        $d = ConvenioDocumento::firstOrFail();
        $this->assertSame([$cv->id, 'tablas'], [$d->convenio_id, $d->tipo]);
        $this->assertSame($desp->id, $d->documento->cliente_id);
        // Un usuario del portal no ve convenios ni puede entrar en la pantalla
        $portal = $this->usuario(User::CLIENTE_ADMIN, $this->a);
        $this->actingAs($portal);
        $this->assertSame(0, Convenio::count());
        $this->assertSame(0, ConvenioVersion::count());
        $this->assertContains($this->actingAs($portal)->get('/laboral/gestion/convenios')->status(), [302, 403, 404]);
    }

    public function test_migracion_del_convenio_antiguo(): void
    {
        LaboralConfig::create(['cliente_id' => $this->a->id, 'datos' => ['conv' => ['nombre' => 'Hostelería de Huelva', 'codigo' => '21000045011981', 'jornada' => '1792', 'vacaciones' => '30', 'pagas' => '14',
            'cats' => [['cat' => 'Camarero', 'grupo' => '5', 'base' => 1300, 'plus' => 80, 'unidad' => 'mes'], ['cat' => 'Ayudante', 'grupo' => '7', 'base' => 1200, 'plus' => 0]],
            'pluses' => [['nombre' => 'Plus de transporte', 'unidad' => 'dia_trabajado', 'tipo' => 'importe', 'valor' => 3, 'auto' => true, 'cotiza' => true, 'tributa' => true]],
            'antig' => ['periodo' => 3, 'tipo' => 'importe', 'valor' => 20, 'tope' => null]]]]);
        $m = require database_path('migrations/2026_11_19_000260_convenios_versionados.php');
        (fn () => $this->copiarDatosExistentes())->call($m);
        $conv = Nomina::config($this->a)['conv'];
        $this->assertSame('Hostelería de Huelva', $conv['nombre']);
        $this->assertSame([1300.0, 80.0], [$conv['cats'][0]['base'], $conv['cats'][0]['plus']]);
        $this->assertSame(0.0, $conv['cats'][1]['plus']);
        $this->assertSame('Plus de transporte', $conv['pluses'][0]['nombre']);
        $this->assertSame(3, $conv['antig']['periodo']);
        $this->assertSame('migracion', ConvenioVersion::firstOrFail()->fuente);
    }

    public function test_nomina_emitida_no_cambia_al_editar_el_convenio(): void
    {
        [, , $v2] = $this->dosVersiones();
        $t = Trabajador::create(['cliente_id' => $this->a->id, 'estado' => 'Alta', 'alta' => '2026-01-01', 'contrato' => '100', 'datos' => Nomina::rellenar(['nombre' => 'Oficial Prueba',
            'nif' => '29112233Y', 'naf' => '211234567890', 'contrato' => Nomina::CONTRATOS[0], 'alta' => '2026-01-01', 'convCat' => 'Oficial', 'grupo' => 8, 'salBase' => 1600, 'pagas' => 14, 'jor' => 100], $this->a)]);
        $url = '/laboral/nominas?anio=2026&mes=8&t='.$t->id;
        $html = $this->en()->get($url)->assertOk()->getContent();
        preg_match_all('/name="l\[(\d+)\]\[(id|cod|concepto|importe|clase|cobro)\]"(?: value="([^"]*)")?/', $html, $mm, PREG_SET_ORDER);
        $l = [];
        foreach ($mm as $x) {
            $l[$x[1]][$x[2]] = html_entity_decode($x[3] ?? '');
        }
        $this->en()->post('/laboral/nominas/'.$t->id.'/guardar', ['anio' => 2026, 'mes' => 8, 'accion' => 'emitir', 'l' => array_values($l)])->assertRedirect();
        $this->assertTrue(NominaMes::firstOrFail()->emitida());
        $w = fn () => Nomina::rellenar($t->fresh()->ficha(), $this->a);
        $antes = app(Nomina::class)->nomina($w(), 2026, 8, [], 0);
        // Se quitan las pagas extra del convenio: cambia la prorrata de los borradores, no la nómina emitida
        Convenios::guardar($v2, ['extras' => [['nombre' => 'Paga única', 'mes' => 12, 'calculo' => 'importe', 'dias' => null, 'importe' => 50, 'plus' => false, 'antig' => false]]]);
        $despues = app(Nomina::class)->nomina($w(), 2026, 8, [], 0);
        $this->assertSame([$antes['base'], $antes['prorB'], $antes['liq']], [$despues['base'], $despues['prorB'], $despues['liq']]);
        $this->assertNotEquals($antes['prorB'], app(Nomina::class)->nomina($w(), 2026, 9, [], 0)['prorB']);
    }
}
