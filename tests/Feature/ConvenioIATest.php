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
        $this->actingAs($this->as)->withSession(['cliente_activo' => $this->a->id])->post(route('laboral.convenios.ia'), ['pdf' => $pdf])
            ->assertRedirect(route('laboral.convenios'))->assertSessionHas('ok');

        $P = Nomina::config($this->a);
        $this->assertSame('Convenio colectivo de la construcción de Huelva', $P['conv']['nombre']);
        $this->assertSame('15', $P['conv']['pagas']);
        $this->assertCount(3, $P['conv']['extras']);
        $d = $t->fresh()->datos;
        $this->assertSame(['Diario', 50.0, 10], [$d['salTipo'], (float) $d['salDia'], (int) $d['grupo']]);

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

    public function test_sin_clave_no_hay_lectura_y_solo_el_admin_la_configura(): void
    {
        $this->actingAs($this->as)->withSession(['cliente_activo' => $this->a->id])->get(route('laboral.convenios'))->assertOk()
            ->assertDontSee('Subir PDF y leer con IA')->assertSee('tiene que configurar la clave');
        $this->actingAs($this->as)->post(route('laboral.convenios.ia.clave'), ['clave' => 'sk-ant-'.str_repeat('a', 30)])->assertForbidden();
        $admin = $this->usuario(User::ADMIN);
        $this->actingAs($admin)->post(route('laboral.convenios.ia.clave'), ['clave' => 'no-vale'])->assertSessionHasErrors('conv');
        $this->actingAs($admin)->post(route('laboral.convenios.ia.clave'), ['clave' => 'sk-ant-'.str_repeat('a', 30)])->assertSessionHas('ok');
        $this->assertTrue(ConvenioIA::configurada());
        $this->actingAs($admin)->withSession(['cliente_activo' => $this->a->id])->get(route('laboral.convenios'))->assertSee('Subir PDF y leer con IA');
    }
}
