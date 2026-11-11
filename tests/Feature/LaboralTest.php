<?php

namespace Tests\Feature;

use App\Models\Apunte;
use App\Models\Asiento;
use App\Models\Cliente;
use App\Models\LaboralMes;
use App\Models\LaboralMovimiento;
use App\Models\Trabajador;
use App\Models\User;
use App\Services\Nomina;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LaboralTest extends TestCase
{
    use RefreshDatabase;

    private Cliente $a;

    private User $as;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-11 10:00:00');
        CarbonImmutable::setTestNow('2026-11-11 10:00:00');
        $this->a = $this->cliente('Café Las Colonias SL', 'B21890124');
        $this->as = $this->usuario(User::ASESOR);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function en(?User $u = null)
    {
        return $this->actingAs($u ?? $this->as)->withSession(['cliente_activo' => $this->a->id]);
    }

    private function ficha(array $o = []): array
    {
        return array_merge(['nom1' => 'María', 'ape1' => 'López', 'ape2' => 'Ruiz', 'tipoDoc' => 'DNI', 'nif' => '29112233Y', 'naf' => '21 1234567890', 'ccc' => '21123456789',
            'contrato' => '100', 'alta' => '2026-01-01', 'jor' => '100', 'grupo' => '5', 'salBase' => '1.500,00', 'plus' => '0', 'pagas' => '14', 'irpfModo' => 'auto',
            'claus' => ['conf', 'reg'], 'm145' => ['sit' => '3', 'hijos' => [['anio' => '2020', 'disc' => 'No', 'entero' => '1']]]], $o);
    }

    public function test_pantallas_de_laboral_y_alta_de_trabajador(): void
    {
        foreach (['/laboral', '/laboral/trabajador/nuevo', '/laboral/nominas', '/laboral/seguros-sociales', '/laboral/altas-y-bajas', '/laboral/parametros', '/laboral?con=red'] as $u) {
            $this->en()->get($u)->assertOk();
        }
        $this->en()->post('/laboral/trabajador/nuevo', $this->ficha())->assertRedirect();
        $t = Trabajador::firstOrFail();
        $this->assertSame('María López Ruiz', $t->datos['nombre']);
        $this->assertSame('211234567890', $t->datos['naf']);
        $this->assertSame('100 · Indefinido a tiempo completo · ordinario', $t->datos['contrato']);
        $this->assertSame(21000.0, (float) $t->datos['sal']);
        $this->assertCount(1, $t->datos['m145']['hijos']);
        $this->assertSame('Alta', LaboralMovimiento::firstOrFail()->tipo);
        $this->assertSame('Pendiente', LaboralMovimiento::firstOrFail()->estado);
        // La ficha y la plantilla muestran los datos
        $this->en()->get('/laboral')->assertSee('María López Ruiz')->assertSee('21.000,00 €');
        $this->en()->get('/laboral/trabajador/'.$t->id.'?sec=ss')->assertOk()->assertSee('Comunicar el alta');
        // Documentos imprimibles
        foreach (['contrato', 'copia', 'comunicacion', 'm145'] as $d) {
            $this->en()->get('/laboral/trabajador/'.$t->id.'/documento/'.$d)->assertOk()->assertSee('María López Ruiz');
        }
        $this->en()->get('/laboral/trabajador/'.$t->id.'/documento/certificado')->assertRedirect();
    }

    public function test_validaciones_de_la_ficha(): void
    {
        $this->en()->from('/laboral/trabajador/nuevo')->post('/laboral/trabajador/nuevo', $this->ficha(['nif' => '29112233A']))->assertSessionHasErrors('ficha');
        $this->en()->post('/laboral/trabajador/nuevo', $this->ficha(['naf' => '123']))->assertSessionHasErrors('ficha');
        $this->en()->post('/laboral/trabajador/nuevo', $this->ficha(['contrato' => '402', 'fin' => '']))->assertSessionHasErrors('ficha');
        $this->en()->post('/laboral/trabajador/nuevo', $this->ficha(['contrato' => '402', 'fin' => '2026-06-30', 'causa' => 'corta']))->assertSessionHasErrors('ficha');
        $this->assertSame(0, Trabajador::count());
        $this->en()->post('/laboral/trabajador/nuevo', $this->ficha(['contrato' => '402', 'fin' => '2026-06-30', 'causa' => 'Acumulación de tareas por la campaña de verano']))->assertSessionHasNoErrors();
        $this->assertSame(1, Trabajador::count());
    }

    public function test_calculo_de_la_nomina_y_cotizaciones_2026(): void
    {
        $w = Nomina::rellenar(['id' => 1, 'contrato' => Nomina::CONTRATOS[0], 'grupo' => 5, 'salBase' => 1500, 'plus' => 0, 'pagas' => 14, 'jor' => 100, 'alta' => '2026-01-01'], $this->a);
        $n = Nomina::calcular($w, 11, [], 10, 1.5);
        // Base = 1.500 + prorrata (1.500 × 2 / 12 = 250) = 1.750
        $this->assertEqualsWithDelta(1750, $n['base'], 0.001);
        $this->assertEqualsWithDelta(1750 * (0.047 + 0.0015 + 0.0155 + 0.001), $n['ssT'], 0.001);
        $this->assertEqualsWithDelta(150, $n['irpf'], 0.001);
        $this->assertEqualsWithDelta(1500 - $n['ssT'] - 150, $n['liq'], 0.001);
        // Paga extra en junio y diciembre; base mínima del grupo
        $this->assertEqualsWithDelta(3000, Nomina::calcular($w, 6, [], 0)['dev'], 0.001);
        $w2 = array_merge($w, ['salBase' => 900, 'grupo' => 1]);
        $this->assertEqualsWithDelta(1989.30, Nomina::calcular($w2, 3, [], 0)['base'], 0.001);
        // Temporal: desempleo 1,60 % y mínimo de retención del 2 %
        $t = array_merge($w, ['contrato' => Nomina::contratoCompleto('402'), 'salBase' => 1000]);
        $this->assertEqualsWithDelta(0.016, Nomina::calcular($t, 3, [], 0)['T']['des'] / Nomina::calcular($t, 3, [], 0)['base'], 0.00001);
        $this->assertSame(2.0, (float) app(Nomina::class)->tipoIrpf($t, 2026, 3, [])['tipo']);
        // Por debajo del límite excluido (situación 3, sin hijos: 15.876 €) no hay retención
        $b = array_merge($w, ['salBase' => 1000]);
        $this->assertSame(0.0, (float) app(Nomina::class)->tipoIrpf($b, 2026, 3, [])['tipo']);
        $this->assertGreaterThan(5, app(Nomina::class)->tipoIrpf(array_merge($w, ['salBase' => 2500]), 2026, 3, [])['tipo']);
    }

    public function test_variables_contabilizar_y_seguros_sociales(): void
    {
        $this->en()->post('/laboral/trabajador/nuevo', $this->ficha());
        $t = Trabajador::firstOrFail();
        $this->en()->post('/laboral/nominas/'.$t->id.'/variables', ['anio' => 2026, 'mes' => 11, 'hx' => '100,00', 'dietas' => '50,00'])->assertRedirect();
        $inc = LaboralMes::firstOrFail()->incidencias;
        $this->assertSame(100.0, (float) $inc[$t->id]['hx']);
        $this->en()->get('/laboral/nominas?anio=2026&mes=11&t='.$t->id)->assertOk()->assertSee('Variables del mes');
        $this->en()->get('/laboral/nominas/'.$t->id.'/recibo?anio=2026&mes=11')->assertOk()->assertSee('Horas extraordinarias')->assertSee('Dietas');
        foreach ([1, 2, 3] as $m) {
            $this->en()->get('/laboral/nominas/'.$t->id.'/recibo?anio=2026&mes=11&modelo='.$m)->assertOk();
        }
        $csv = $this->en()->get('/laboral/nominas?anio=2026&mes=11&exportar=excel');
        $csv->assertOk();
        $this->assertStringContainsString('María López Ruiz', $csv->streamedContent());

        $this->en()->post('/laboral/nominas/contabilizar', ['anio' => 2026, 'mes' => 11])->assertSessionHasNoErrors();
        $a = Asiento::where('origen', 'laboral')->firstOrFail();
        $this->assertSame('Nómina', $a->tipo);
        $this->assertSame('2026-11-30', $a->fecha->toDateString());
        $ap = Apunte::where('asiento_id', $a->id)->get()->keyBy('cuenta');
        $this->assertEqualsWithDelta(1650, (float) $ap['640000000']->debe, 0.001); // 1.500 + 100 h. extra + 50 dietas
        $this->assertEqualsWithDelta((float) $ap->sum('debe'), (float) $ap->sum('haber'), 0.001);
        $this->assertTrue($ap->has('475100000') && $ap->has('476000000') && $ap->has('465000000') && $ap->has('642000000'));
        // No se puede contabilizar dos veces ni cambiar variables
        $this->en()->post('/laboral/nominas/contabilizar', ['anio' => 2026, 'mes' => 11])->assertSessionHasErrors('ficha');
        $this->en()->post('/laboral/nominas/'.$t->id.'/variables', ['anio' => 2026, 'mes' => 11, 'hx' => '1'])->assertSessionHasErrors('ficha');

        // Seguros sociales: confirmar exige la conexión RED
        $this->en()->get('/laboral/seguros-sociales?anio=2026&mes=10')->assertOk()->assertSee('Total liquidación');
        $this->en()->post('/laboral/seguros-sociales/confirmar', ['anio' => 2026, 'mes' => 10])->assertSessionHasErrors('ficha');
        $this->en()->post('/laboral/seguros-sociales/at', ['at' => '3,5'])->assertSessionHasNoErrors();
        $this->assertSame(3.5, (float) Nomina::config($this->a)['at']);
    }

    public function test_parametros_conexion_acciones_y_baja(): void
    {
        $this->en()->post('/laboral/trabajador/nuevo', $this->ficha());
        $t = Trabajador::firstOrFail();
        // Sin conexión, las acciones RED se rechazan
        $this->en()->post('/laboral/trabajador/'.$t->id.'/accion/alta')->assertSessionHasErrors('ficha');
        $this->en()->post('/laboral/parametros', ['conv_nombre' => 'Convenio de hostelería de Huelva', 'conv_pagas' => '14', 'modelo' => 3, 'useImg' => 1,
            'cats' => [['cat' => 'Camarero', 'grupo' => '7', 'base' => '1.300,00', 'plus' => '50']], 'red_email' => 'red@gestoria.es', 'red_aut' => '12345',
            'red_cert' => UploadedFile::fake()->create('autorizado.p12', 3), 'sepe_aut' => 'USR01', 'sepe_mismoCert' => 1, 'cccs' => '21 123456789, 21987654321',
            'hab' => ['100', '402'], 'claus' => ['conf']])->assertSessionHasNoErrors();
        $P = Nomina::config($this->a);
        $this->assertSame('autorizado.p12', $P['red']['cert']);
        $this->assertSame(['21123456789', '21987654321'], $P['cccs']);
        $this->assertSame(1300.0, (float) $P['conv']['cats'][0]['base']);
        $this->assertTrue(Nomina::redOk($P) && Nomina::sepeOk($P));
        // Con conexión: alta comunicada
        $this->en()->post('/laboral/trabajador/'.$t->id.'/accion/alta')->assertSessionHasNoErrors();
        $this->assertSame('Comunicado', LaboralMovimiento::firstOrFail()->estado);
        $this->assertSame('alta', $t->fresh()->datos['docs'][0]['k']);
        // Variación solo si cambian datos de afiliación
        $this->en()->post('/laboral/trabajador/'.$t->id, $this->ficha(['tel' => '600000000']));
        $this->assertSame(1, LaboralMovimiento::count());
        $this->en()->post('/laboral/trabajador/'.$t->id, $this->ficha(['grupo' => '3']));
        $this->assertSame('Variación', LaboralMovimiento::latest('id')->first()->tipo);
        // Baja y certificado de empresa
        $this->en()->post('/laboral/altas-y-bajas/baja', ['trabajador' => $t->id, 'fecha' => '2025-01-01', 'causa' => 'Baja voluntaria'])->assertSessionHasErrors('ficha');
        $this->en()->post('/laboral/altas-y-bajas/baja', ['trabajador' => $t->id, 'fecha' => '2026-11-10', 'causa' => 'Baja voluntaria'])->assertSessionHasNoErrors();
        $this->assertSame('Baja', $t->fresh()->estado);
        $this->en()->get('/laboral/trabajador/'.$t->id.'/documento/certificado')->assertOk()->assertSee('CERTIFICADO DE EMPRESA')->assertSee('Baja voluntaria');
        $this->en()->post('/laboral/altas-y-bajas/comunicar')->assertSessionHasNoErrors();
        $this->assertSame(0, LaboralMovimiento::where('estado', 'Pendiente')->count());
    }

    public function test_aislamiento_entre_empresas_y_portal(): void
    {
        $this->en()->post('/laboral/trabajador/nuevo', $this->ficha());
        $t = Trabajador::firstOrFail();
        $b = $this->cliente('Otra SL', 'B21345673');
        $this->actingAs($this->as)->withSession(['cliente_activo' => $b->id])->get('/laboral/trabajador/'.$t->id)->assertNotFound();
        $this->actingAs($this->as)->withSession(['cliente_activo' => $b->id])->get('/laboral/trabajador/'.$t->id.'/documento/contrato')->assertNotFound();
        $portal = $this->usuario(User::CLIENTE_ADMIN, $this->a);
        $this->actingAs($portal)->get('/laboral')->assertForbidden();
        // Eliminar: solo la administración
        $this->en()->delete('/laboral/trabajador/'.$t->id.'/eliminar')->assertForbidden();
        $this->en($this->usuario(User::ADMIN))->delete('/laboral/trabajador/'.$t->id.'/eliminar')->assertRedirect();
        $this->assertSame(0, Trabajador::count());
    }
}
