<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Trabajador;
use App\Models\User;
use App\Services\ConvenioIA;
use App\Services\Nomina;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Lectura del convenio con IA (respuesta simulada) y aplicación automática a la nómina */
class ConvenioIATest extends TestCase
{
    use RefreshDatabase;

    private Cliente $a;

    private User $as;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-11 10:00:00');
        CarbonImmutable::setTestNow('2026-11-11 10:00:00');
        $this->a = $this->cliente('Construcciones Prueba SL', 'B21890124');
        $this->as = $this->usuario(User::ASESOR);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function respuesta(): array
    {
        return [
            'nombre' => 'Convenio colectivo de la construcción de Huelva', 'codigo' => '21000085011981', 'ambito' => 'Provincia de Huelva', 'vigencia' => '2025-2027', 'anio_tablas' => 2026,
            'jornada_anual' => 1736, 'vacaciones_dias' => 30,
            'tablas' => [
                ['categoria' => 'Peón ordinario', 'grupo_cotizacion' => 10, 'salario_base' => 50.0, 'unidad' => 'dia', 'plus_convenio' => 0, 'articulo' => 'Anexo I'],
                ['categoria' => 'Administrativo', 'grupo_cotizacion' => 5, 'salario_base' => 1500, 'unidad' => 'mes', 'plus_convenio' => 100, 'articulo' => 'Anexo I'],
            ],
            'pagas_extra' => [
                ['nombre' => 'Paga de verano', 'mes_cobro' => 6, 'calculo' => 'dias', 'dias' => 30, 'importe' => null, 'incluye_antiguedad' => true, 'incluye_plus_convenio' => false, 'articulo' => 'Art. 50'],
                ['nombre' => 'Paga de Navidad', 'mes_cobro' => 12, 'calculo' => 'dias', 'dias' => 30, 'importe' => null, 'incluye_antiguedad' => true, 'incluye_plus_convenio' => false, 'articulo' => 'Art. 50'],
                ['nombre' => 'Paga de beneficios', 'mes_cobro' => 3, 'calculo' => 'importe', 'dias' => null, 'importe' => 300, 'incluye_antiguedad' => false, 'incluye_plus_convenio' => false, 'articulo' => 'Art. 51'],
            ],
            'antiguedad' => ['existe' => true, 'periodo_anios' => 3, 'tipo' => 'importe', 'valor' => 20, 'tope_periodos' => 5, 'articulo' => 'Art. 48'],
            'pluses' => [
                ['nombre' => 'Plus de transporte', 'unidad' => 'dia_trabajado', 'tipo' => 'importe', 'valor' => 4, 'automatico' => true, 'cotiza' => true, 'tributa' => true, 'articulo' => 'Art. 52'],
                ['nombre' => 'Plus de nocturnidad', 'unidad' => 'hora', 'tipo' => 'importe', 'valor' => 1.5, 'automatico' => false, 'cotiza' => true, 'tributa' => true, 'articulo' => 'Art. 53'],
            ],
            'it' => ['contingencias_comunes' => [['desde_dia' => 1, 'hasta_dia' => 20, 'porcentaje' => 75], ['desde_dia' => 21, 'hasta_dia' => null, 'porcentaje' => 100]], 'accidente_trabajo' => [['desde_dia' => 1, 'hasta_dia' => null, 'porcentaje' => 100]], 'articulo' => 'Art. 60'],
            'horas_extra' => ['tipo' => 'importe', 'valor' => 15, 'articulo' => 'Art. 30'],
            'avisos' => ['El grupo de cotización del peón se ha deducido de sus funciones.'],
        ];
    }

    public function test_lee_el_convenio_y_lo_aplica_a_la_nomina(): void
    {
        $this->app->instance(ConvenioIA::class, new ConvenioIA(fn ($b64, $prompt, $schema) => json_encode($this->respuesta())));
        // Peón dado de alta hace 7 años con salario mensual por debajo del convenio
        $t = Trabajador::create(['cliente_id' => $this->a->id, 'estado' => 'Alta', 'alta' => '2019-05-01', 'contrato' => '100', 'datos' => Nomina::rellenar(['nombre' => 'Beka Prueba', 'nif' => 'X1234567L', 'naf' => '211234567890',
            'contrato' => Nomina::CONTRATOS[0], 'alta' => '2019-05-01', 'convCat' => 'Peón ordinario', 'grupo' => 7, 'salBase' => 900, 'pagas' => 14, 'jor' => 100], $this->a)]);
        $this->assertArrayNotHasKey('_conv', $t->fresh()->datos);

        $pdf = UploadedFile::fake()->createWithContent('convenio.pdf', "%PDF-1.4\nconvenio");
        $en = fn () => $this->actingAs($this->as)->withSession(['cliente_activo' => $this->a->id]);
        $en()->post(route('laboral.convenios.ia'), ['pdf' => $pdf])->assertRedirect(route('laboral.convenios', ['revisar' => 1]))->assertSessionHas('ok');
        // Solo es una propuesta: ni el convenio ni la plantilla cambian todavía
        $this->assertSame([], Nomina::config($this->a)['conv']['cats']);
        $en()->get(route('laboral.convenios'))->assertSee('Propuesta pendiente de revisar')->assertSee('Nuevas: Peón ordinario, Administrativo')->assertSee('Paga de beneficios (marzo)');
        $en()->post(route('laboral.convenios.propuesta'), ['accion' => 'aplicar'])->assertRedirect(route('laboral.convenios'))->assertSessionHas('ok');
        $this->assertNull(Nomina::config($this->a)['convPropuesta']);

        $P = Nomina::config($this->a);
        $this->assertSame('Convenio colectivo de la construcción de Huelva', $P['conv']['nombre']);
        $this->assertSame('15', $P['conv']['pagas']);
        $this->assertCount(3, $P['conv']['extras']);
        // Nunca se cambian salarios como efecto de una importación: se avisa de quién queda por debajo
        $d = $t->fresh()->datos;
        $this->assertSame(['Mensual', 900.0, 7], [$d['salTipo'], (float) $d['salBase'], (int) $d['grupo']]);
        $en()->get(route('laboral.convenios'))->assertSee('Trabajadores por debajo del convenio')->assertSee('Beka Prueba');
        // La asesora corrige la ficha a mano
        $t->update(['datos' => array_merge($d, ['salTipo' => 'Diario', 'salDia' => 50, 'grupo' => 10])]);

        $w = Nomina::rellenar($t->fresh()->ficha(), $this->a);
        // 7 años: 2 trienios × 20 € = 40 €
        $this->assertSame([40.0, 2], array_slice(Nomina::antiguedad($w, 2026, 11), 0, 2));
        // Noviembre 2026: 30 días × 50 + antigüedad 40 + transporte 21 días laborables × 4
        $n = Nomina::calcular($w, 11, [], 0);
        $this->assertEqualsWithDelta(30 * 50 + 40 + 21 * 4, $n['dev'], 0.01);
        // Junio: + paga de verano de 30 días con antigüedad (1.500 + 40); marzo: + paga de beneficios de 300 €
        $this->assertEqualsWithDelta(30 * 50 + 40 + Nomina::diasLaborables(2026, 6) * 4 + 1540, Nomina::calcular($w, 6, [], 0)['dev'], 0.01);
        $this->assertEqualsWithDelta(31 * 50 + 40 + Nomina::diasLaborables(2026, 3) * 4 + 300, Nomina::calcular($w, 3, [], 0)['dev'], 0.01);

        $this->actingAs($this->as)->withSession(['cliente_activo' => $this->a->id])->get(route('laboral.convenios'))->assertOk()
            ->assertSee('Leído con IA')->assertSee('Paga de beneficios en marzo')->assertSee('por cada 3 años')->assertSee('Plus de nocturnidad')
            ->assertSee('días 21 en adelante al 100 %')->assertSee('El grupo de cotización del peón');
    }

    public function test_convenio_a_mano_conectado_con_la_nomina(): void
    {
        $t = Trabajador::create(['cliente_id' => $this->a->id, 'estado' => 'Alta', 'alta' => '2020-01-01', 'contrato' => '100', 'datos' => Nomina::rellenar(['nombre' => 'Peón Prueba', 'nif' => 'X1234567L',
            'naf' => '211234567890', 'contrato' => Nomina::CONTRATOS[0], 'alta' => '2020-01-01', 'convCat' => 'Peón', 'grupo' => 7, 'salBase' => 900, 'pagas' => 14, 'jor' => 100], $this->a)]);
        $en = fn () => $this->actingAs($this->as)->withSession(['cliente_activo' => $this->a->id]);
        $en()->get(route('laboral.convenios'))->assertOk()->assertSee('Identificación y publicación')->assertSee('Guarda primero la identificación');
        // Una paga por días sin indicar los días: no se guarda
        $en()->post(route('laboral.convenios.guardar'), ['_enviado' => 1, 'conv_nombre' => 'Convenio a mano', 'cats' => [['cat' => 'Peón', 'grupo' => '10', 'unidad' => 'dia', 'base' => '45,50', 'plus' => '0']],
            'extras' => [['nombre' => 'Verano', 'mes' => '7', 'calculo' => 'dias', 'dias' => '']]])->assertSessionHasErrors('conv');
        $en()->post(route('laboral.convenios.guardar'), ['_enviado' => 1, 'conv_nombre' => 'Convenio a mano',
            'cats' => [['cat' => 'Peón', 'grupo' => '10', 'unidad' => 'dia', 'base' => '45,50', 'plus' => '0']],
            'extras' => [['nombre' => 'Verano', 'mes' => '7', 'calculo' => 'dias', 'dias' => '30'], ['nombre' => 'Navidad', 'mes' => '12', 'calculo' => 'dias', 'dias' => '30', 'antig' => '1']],
            'ant_si' => '1', 'ant_periodo' => '5', 'ant_tipo' => 'porcentaje', 'ant_valor' => '5', 'ant_tope' => '',
            'pluses' => [['nombre' => 'Plus de asistencia', 'valor' => '60,00', 'tipo' => 'importe', 'unidad' => 'mes', 'auto' => '1', 'cotiza' => '1', 'tributa' => '1'],
                ['nombre' => 'Plus de nocturnidad', 'valor' => '2,00', 'tipo' => 'importe', 'unidad' => 'hora', 'cotiza' => '1', 'tributa' => '1']],
            'it_cc' => [['desde' => '4', 'hasta' => '', 'pct' => '100']], 'hx_tipo' => 'importe', 'hx_valor' => '14,00'])
            ->assertRedirect()->assertSessionHas('ok');
        $P = Nomina::config($this->a);
        $this->assertSame(['14', 'dia'], [$P['conv']['pagas'], $P['conv']['cats'][0]['unidad']]);
        $this->assertSame(['porcentaje', 5.0, 5], [$P['conv']['antig']['tipo'], (float) $P['conv']['antig']['valor'], $P['conv']['antig']['periodo']]);
        $d = $t->fresh()->datos;
        $this->assertSame([900.0, 7], [(float) $d['salBase'], (int) $d['grupo']]);
        $t->update(['datos' => array_merge($d, ['salTipo' => 'Diario', 'salDia' => 45.5, 'grupo' => 10])]);
        $w = Nomina::rellenar($t->fresh()->ficha(), $this->a);
        // Noviembre 2026: 30 días × 45,50 + plus 60 + antigüedad (1 quinquenio: 5 % de 1.365) = 68,25
        $this->assertEqualsWithDelta(30 * 45.5 + 60 + 68.25, Nomina::calcular($w, 11, [], 0)['dev'], 0.01);
        // Julio: + paga de verano de 30 días (sin antigüedad)
        $this->assertEqualsWithDelta(31 * 45.5 + 60 + 68.25 + 1365, Nomina::calcular($w, 7, [], 0)['dev'], 0.01);
        // El editor muestra lo guardado en sus secciones
        $en()->get(route('laboral.convenios'))->assertSee('value="Plus de nocturnidad"', false)->assertSee('value="14,00"', false)->assertSee('Pagas extraordinarias')->assertSee('Incapacidad temporal · mejora del convenio');
        // La nocturnidad y las horas extra se ofrecen en el Integral con su precio
        $en()->get(route('laboral.integral', ['t' => $t->id, 'anio' => 2026, 'mes' => 11]))->assertOk()->assertSee('Convenio: 2,00 € por hora')->assertSee('Convenio: 14,00 € la hora');
    }

    public function test_lectura_gratuita_pegando_la_respuesta_de_claude(): void
    {
        $this->assertStringContainsString('"pagas_extra"', ConvenioIA::instrucciones());
        $en = fn () => $this->actingAs($this->as)->withSession(['cliente_activo' => $this->a->id]);
        $en()->post(route('laboral.convenios.ia.pegar'), ['respuesta' => 'Aquí tienes { "nombre": "x" }'])->assertSessionHasErrors('conv');
        $this->assertSame([], Nomina::config($this->a)['conv']['cats']);
        // Respuesta de claude.ai con texto y bloque de código alrededor del JSON
        $en()->post(route('laboral.convenios.ia.pegar'), ['respuesta' => "Aquí está:\n```json\n".json_encode($this->respuesta(), JSON_UNESCAPED_UNICODE)."\n```"])
            ->assertRedirect(route('laboral.convenios', ['revisar' => 1]))->assertSessionHas('ok');
        $this->assertSame([], Nomina::config($this->a)['conv']['cats']);
        // Descartar no cambia nada; volver a pegar y aprobar sí
        $en()->post(route('laboral.convenios.propuesta'), ['accion' => 'descartar'])->assertSessionHas('ok');
        $this->assertSame([], Nomina::config($this->a)['conv']['cats']);
        $en()->post(route('laboral.convenios.ia.pegar'), ['respuesta' => json_encode($this->respuesta())]);
        $en()->post(route('laboral.convenios.ia.pegar'), ['respuesta' => json_encode($this->respuesta())]);
        $en()->post(route('laboral.convenios.propuesta'), ['accion' => 'aplicar']);
        $P = Nomina::config($this->a);
        // Reintentar no duplica categorías
        $this->assertCount(2, $P['conv']['cats']);
        $this->assertSame(3, $P['conv']['antig']['periodo']);
        $en()->get(route('laboral.convenios'))->assertSee('Leído con Claude (claude.ai)');
    }

    public function test_sin_clave_no_hay_lectura_y_solo_el_admin_la_configura(): void
    {
        $this->actingAs($this->as)->withSession(['cliente_activo' => $this->a->id])->get(route('laboral.convenios'))->assertOk()
            ->assertDontSee('Subir PDF y leer con IA')->assertSee('Copiar instrucciones')->assertSee('Sin coste, con tu suscripción de Claude');
        $this->actingAs($this->as)->post(route('laboral.convenios.ia.clave'), ['clave' => 'sk-ant-'.str_repeat('a', 30)])->assertForbidden();
        $admin = $this->usuario(User::ADMIN);
        $this->actingAs($admin)->post(route('laboral.convenios.ia.clave'), ['clave' => 'no-vale'])->assertSessionHasErrors('conv');
        $this->actingAs($admin)->post(route('laboral.convenios.ia.clave'), ['clave' => 'sk-ant-'.str_repeat('a', 30)])->assertSessionHas('ok');
        $this->assertTrue(ConvenioIA::configurada());
        $this->actingAs($admin)->withSession(['cliente_activo' => $this->a->id])->get(route('laboral.convenios'))->assertSee('Subir PDF y leer con IA');
    }
}
