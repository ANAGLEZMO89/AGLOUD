<?php

namespace Tests\Feature;

use App\Models\Apunte;
use App\Models\Asiento;
use App\Models\Cliente;
use App\Models\LaboralMes;
use App\Models\LaboralMovimiento;
use App\Models\NominaMes;
use App\Models\Trabajador;
use App\Models\TrabajadorCondicion;
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
        $this->en()->post('/laboral/trabajador/nuevo', $this->ficha(['naf' => '123']))->assertSessionHasErrors('ficha')->assertRedirect('/laboral/trabajador/nuevo?sec=afil');
        $this->en()->post('/laboral/trabajador/nuevo', $this->ficha(['salBase' => '0']))->assertRedirect('/laboral/trabajador/nuevo?sec=retrib');
        $this->en()->followingRedirects()->post('/laboral/trabajador/nuevo', $this->ficha(['salBase' => '0', 'nom1' => 'Beka', '_ficha' => '1']))
            ->assertSee('No se ha guardado la ficha: indica el salario.')->assertSee('value="Beka"', false);
        $this->en()->post('/laboral/trabajador/nuevo', $this->ficha(['contrato' => '402', 'fin' => '']))->assertSessionHasErrors('ficha');
        $this->en()->post('/laboral/trabajador/nuevo', $this->ficha(['contrato' => '402', 'fin' => '2026-06-30', 'causa' => 'corta']))->assertSessionHasErrors('ficha');
        $this->assertSame(0, Trabajador::count());
        $this->en()->post('/laboral/trabajador/nuevo', $this->ficha(['contrato' => '402', 'fin' => '2026-06-30', 'causa' => 'Acumulación de tareas por la campaña de verano']))->assertSessionHasNoErrors();
        $this->assertSame(1, Trabajador::count());
    }

    public function test_grupo_10_y_salario_diario(): void
    {
        $this->en()->get('/laboral/trabajador/nuevo?sec=afil')->assertOk()->assertSee('10 · Peones')->assertSee('11 · Trabajadores menores de 18 años');
        $this->en()->get('/laboral/trabajador/nuevo?sec=retrib')->assertOk()->assertSee('Salario base diario (€/día)');
        $this->en()->post('/laboral/trabajador/nuevo', $this->ficha(['grupo' => '10', 'salTipo' => 'Diario', 'salDia' => '50,25', 'diasExtra' => '30', 'salBase' => '']))->assertSessionHasNoErrors()->assertRedirect();
        $t = Trabajador::firstOrFail();
        $this->assertSame([10, 'Diario', 50.25, 30], [(int) $t->datos['grupo'], $t->datos['salTipo'], (float) $t->datos['salDia'], (int) $t->datos['diasExtra']]);
        // Anual: 365 días + 2 pagas de 30 días
        $this->assertEqualsWithDelta(50.25 * 365 + 2 * 50.25 * 30, Nomina::anual($t->datos), 0.01);
        $w = Nomina::rellenar(['id' => 9, 'contrato' => Nomina::CONTRATOS[0], 'grupo' => 10, 'salTipo' => 'Diario', 'salDia' => 50.25, 'pagas' => 14, 'jor' => 100, 'alta' => '2026-01-01'], $this->a);
        // Febrero de 2026: 28 días; junio (30 días) con paga extra de 30 días, proporcional a lo devengado
        // (alta el 01/01/2026: 181 de los 365 días del devengo, del 01/07/2025 al 30/06/2026); diciembre, entera
        $this->assertEqualsWithDelta(28 * 50.25, Nomina::calcular($w, 2, [], 0)['dev'], 0.001);
        $this->assertEqualsWithDelta(30 * 50.25 + round(30 * 50.25 * 181 / 365, 2), Nomina::calcular($w, 6, [], 0)['dev'], 0.001);
        $this->assertEqualsWithDelta(31 * 50.25 + 30 * 50.25, Nomina::calcular($w, 12, [], 0)['dev'], 0.001);
        // Base mínima diaria del grupo 10 por los días del mes
        $bajo = array_merge($w, ['salDia' => 30]);
        $this->assertEqualsWithDelta(47.48 * 31, Nomina::calcular($bajo, 3, [], 0)['base'], 0.001);
    }

    public function test_calculo_de_la_nomina_y_cotizaciones_2026(): void
    {
        $w = Nomina::rellenar(['id' => 1, 'contrato' => Nomina::CONTRATOS[0], 'grupo' => 5, 'salBase' => 1500, 'plus' => 0, 'pagas' => 14, 'jor' => 100, 'alta' => '2026-01-01'], $this->a);
        $n = Nomina::calcular($w, 11, [], 10, 1.5);
        // Base = 1.500 + prorrata (1.500 × 2 / 12 = 250) = 1.750
        $this->assertEqualsWithDelta(1750, $n['base'], 0.001);
        $this->assertEqualsWithDelta(1750 * (0.047 + 0.0015 + 0.0155 + 0.001), $n['ssT'], 0.02);
        $this->assertEqualsWithDelta(150, $n['irpf'], 0.001);
        $this->assertEqualsWithDelta(1500 - $n['ssT'] - 150, $n['liq'], 0.001);
        // Paga extra en junio y diciembre; base mínima del grupo
        // (la de verano, proporcional a 181 de 365 días por el alta del 01/01/2026; la de Navidad, entera)
        $this->assertEqualsWithDelta(1500 + round(1500 * 181 / 365, 2), Nomina::calcular($w, 6, [], 0)['dev'], 0.001);
        $this->assertEqualsWithDelta(3000, Nomina::calcular($w, 12, [], 0)['dev'], 0.001);
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
        $this->en()->get('/laboral/nominas?anio=2026&mes=11&t='.$t->id)->assertOk()->assertSee('Emitir nómina');
        $this->en()->get('/laboral/nominas?anio=2026&mes=11&vista=variables&t='.$t->id)->assertOk()->assertSee('Variables de noviembre')->assertSee('Otras variables del mes');
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
        $this->en()->post('/laboral/parametros', ['modelo' => 3, 'useImg' => 1, 'red_email' => 'red@gestoria.es', 'red_aut' => '12345',
            'red_cert' => UploadedFile::fake()->create('autorizado.p12', 3), 'sepe_aut' => 'USR01', 'sepe_mismoCert' => 1])->assertSessionHasNoErrors();
        $this->en()->post('/laboral/gestion/convenios', ['conv_nombre' => 'Convenio de hostelería de Huelva', 'conv_pagas' => '14',
            'cats' => [['cat' => 'Camarero', 'grupo' => '7', 'base' => '1.300,00', 'plus' => '50']]])->assertSessionHasNoErrors();
        $this->en()->post('/laboral/gestion/ccc', ['f' => [['ccc' => '21 123456789', 'denom' => 'Cafetería', 'regimen' => 'General'], ['ccc' => '21987654321', 'regimen' => 'Sistema Especial Agrario']]])->assertSessionHasNoErrors();
        $this->en()->post('/laboral/gestion/ccc', ['f' => [['ccc' => '2112345']]])->assertSessionHasErrors('ficha');
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
        // Cambiar el grupo exige la fecha de efecto (histórico de condiciones)
        $this->en()->post('/laboral/trabajador/'.$t->id, $this->ficha(['grupo' => '3']))->assertSessionHasErrors('ficha');
        $this->en()->post('/laboral/trabajador/'.$t->id, $this->ficha(['grupo' => '3', 'efectos' => ['2026-12-01'], 'motivo' => ['Ascenso']]))->assertSessionHasNoErrors();
        $this->assertSame('Variación', LaboralMovimiento::latest('id')->first()->tipo);
        $this->assertSame([$t->fresh()->alta->toDateString(), '2026-12-01'], TrabajadorCondicion::orderBy('efectos_desde')->get()->map(fn ($x) => $x->efectos_desde->toDateString())->all());
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

    public function test_menu_gestion_modelos_y_parametros_de_contrato(): void
    {
        $this->a->setSeccion('bancos', [['iban' => 'ES9121000418450200051332', 'bic' => 'CAIXESBBXXX', 'entidad' => 'CaixaBank', 'principal' => true]]);
        $this->a->save();
        $this->en()->post('/laboral/trabajador/nuevo', $this->ficha(['iban' => 'ES91 2100 0418 4502 0005 1332', 'agrario' => '1']));
        $this->en()->post('/laboral/trabajador/nuevo', $this->ficha(['nom1' => 'Juan', 'nif' => '29445566T', 'naf' => '211111111111', 'contrato' => '200', 'jor' => '50', 'salBase' => '700']));
        [$t1, $t2] = Trabajador::orderBy('id')->get()->all();
        foreach (['/laboral/seguridad-social/cra', '/laboral/seguridad-social/agrario', '/laboral/seguridad-social/planes-de-pensiones', '/laboral/gestion/remesa', '/laboral/gestion/ccc',
            '/laboral/gestion/comunica', '/laboral/gestion/convenios', '/laboral/gestion/calculo', '/laboral/modelos/145', '/laboral/modelos/111', '/laboral/modelos/190', '/laboral/parametros-contrato?c=402',
            '/laboral/trabajador/'.$t1->id.'/texto-contrato'] as $u) {
            $this->en()->get($u)->assertOk()->assertSee('Cret@ - Sistema de Liquidación Directa')->assertSee('Remesa transferencia de nóminas')->assertSee('Modelo 190')->assertSee('Parámetros contrato');
        }
        // CRA y AFI
        $this->en()->post('/laboral/seguridad-social/cra', ['cra' => ['sb' => '0001']])->assertSessionHasNoErrors();
        $this->en()->get('/laboral/seguridad-social/cra?anio=2026&mes=11')->assertSee('0001')->assertSee('Salario base');
        $this->en()->post('/laboral/seguridad-social/afi/agr', ['anio' => 2026, 'mes' => 11, 'v' => [$t1->id => '40']])->assertSessionHasErrors('ficha');
        $this->en()->post('/laboral/seguridad-social/afi/agr', ['anio' => 2026, 'mes' => 11, 'v' => [$t1->id => '18']])->assertSessionHasNoErrors();
        $this->en()->post('/laboral/seguridad-social/afi/pp', ['anio' => 2026, 'mes' => 11, 'v' => [$t2->id => '30,00']])->assertSessionHasNoErrors();
        $afi = LaboralMes::where('anio', 2026)->where('mes', 11)->firstOrFail()->afi;
        $this->assertSame(18, $afi['agr'][$t1->id]);
        $this->assertSame(30.0, (float) $afi['pp'][$t2->id]);
        $this->en()->get('/laboral/seguridad-social/agrario?anio=2026&mes=11')->assertSee('María López Ruiz')->assertDontSee('Juan López Ruiz');
        // Remesa SEPA: el trabajador sin IBAN bloquea; con IBAN se genera el XML
        $this->en()->post('/laboral/gestion/remesa', ['anio' => 2026, 'mes' => 11, 'cuenta' => 0, 'fecha' => '2026-11-30', 't' => [$t2->id]])->assertSessionHasErrors('ficha');
        $r = $this->en()->post('/laboral/gestion/remesa', ['anio' => 2026, 'mes' => 11, 'cuenta' => 0, 'fecha' => '2026-11-30', 'concepto' => 'Nómina noviembre 2026', 't' => [$t1->id]]);
        $r->assertOk();
        $xml = $r->getContent();
        $this->assertStringContainsString('pain.001.001.03', $xml);
        $this->assertStringContainsString('<Cd>SALA</Cd>', $xml);
        $this->assertStringContainsString('<IBAN>ES9121000418450200051332</IBAN>', $xml);
        $this->assertStringContainsString('<NbOfTxs>1</NbOfTxs>', $xml);
        $this->assertNotFalse(simplexml_load_string($xml));
        $this->assertNotNull(LaboralMes::where('mes', 11)->first()->remesa_at);
        // Cálculo masivo con filtros y recibos en bloque
        $this->en()->get('/laboral/gestion/calculo?anio=2026&mes=11&tipo=parcial')->assertSee('Juan López Ruiz')->assertDontSee('María López Ruiz</span>', false);
        $this->en()->get('/laboral/gestion/calculo/recibos?anio=2026&mes=11')->assertOk()->assertSee('Juan López Ruiz')->assertSee('María López Ruiz');
        $this->en()->get('/laboral/gestion/calculo?anio=2026&mes=11&exportar=csv')->assertOk();
        // Modelos 111 y 190
        $this->en()->get('/laboral/modelos/111?anio=2026&t=4')->assertSee('Nº de perceptores');
        $csv = $this->en()->get('/laboral/modelos/190?anio=2026&exportar=excel');
        $this->assertStringContainsString('29112233Y', $csv->streamedContent());
        // Parámetros de contrato: cláusula propia, plantilla de la modalidad y texto del trabajador
        $this->en()->post('/laboral/parametros-contrato', ['c' => '100', 'texto' => '**Primera.** Contrato de {trabajador} con {empresa}.', 'claus' => ['conf'], 'hab' => ['100', '200'], 'clausDef' => ['reg'],
            'extra' => [['l' => 'Uniforme', 'txt' => 'La empresa facilitará el uniforme de trabajo.']]])->assertSessionHasNoErrors();
        $P = Nomina::config($this->a);
        $this->assertCount(1, $P['clausExtra']);
        $kx = $P['clausExtra'][0]['k'];
        $this->assertSame(['conf'], Nomina::clausulasDe($P, '100'));
        $this->assertSame(['reg'], Nomina::clausulasDe($P, '200'));
        $this->en()->get('/laboral/trabajador/'.$t1->id.'/documento/contrato')->assertSee('Contrato de María López Ruiz con Café Las Colonias SL', false);
        $this->en()->get('/laboral/trabajador/nuevo')->assertSee('Uniforme');
        $this->en()->post('/laboral/trabajador/'.$t1->id.'/texto-contrato', ['texto' => '**Primera.** Texto especial para {trabajador}.'])->assertSessionHasNoErrors();
        $this->en()->get('/laboral/trabajador/'.$t1->id.'/documento/contrato')->assertSee('Texto especial para María López Ruiz')->assertDontSee('Contrato de María');
        $this->en()->post('/laboral/trabajador/'.$t1->id.'/texto-contrato', ['restablecer' => 1, 'texto' => 'x'])->assertSessionHasNoErrors();
        $this->assertSame('', $t1->fresh()->datos['contratoTexto']);
        // El marcador escapa el contenido
        [$h] = Nomina::renderContrato('{trabajador}', ['trabajador' => '<b>x</b>']);
        $this->assertStringNotContainsString('<b>x</b>', $h);
        $this->assertNotEmpty($kx);
    }

    public function test_alta_de_trabajador_autonomo(): void
    {
        $this->en()->post('/laboral/trabajador/nuevo', $this->ficha());
        // Autónomo: sin contrato ni salario
        $this->en()->post('/laboral/trabajador/nuevo', ['regimen' => 'Autónomo', 'nom1' => 'Agustín', 'ape1' => 'Pérez', 'tipoDoc' => 'DNI', 'nif' => '29445566T', 'naf' => '211111111111',
            'autAlta' => '2026-03-01', '_aut' => '1', 'autTipo' => 'Autónomo colaborador familiar', 'autBase' => '1.000,00', 'autCuota' => '310,00', 'autCese' => '1', 'iae' => '671.4'])
            ->assertSessionHasNoErrors()->assertSessionHas('ok', fn ($m) => str_contains($m, 'autónomo'));
        $a = Trabajador::where('contrato', 'AUT')->firstOrFail();
        $this->assertSame('Autónomo', $a->datos['regimen']);
        $this->assertSame('2026-03-01', $a->alta->toDateString());
        $this->assertSame(310.0, (float) $a->datos['autCuota']);
        $this->assertTrue($a->datos['autCese']);
        $this->assertStringContainsString('Alta en el RETA', LaboralMovimiento::where('trabajador_id', $a->id)->value('detalle'));
        $this->en()->get('/laboral')->assertSee('Agustín Pérez')->assertSee('RETA')->assertSee('310,00 €/mes');
        $this->en()->get('/laboral/trabajador/'.$a->id.'?sec=aut')->assertOk()->assertSee('Autónomo (RETA)')->assertSee('Cotiza por cese de actividad');
        // Sale en el Integral de nóminas (solo IRPF, sin cotización al Régimen General), pero no en seguros sociales ni control horario
        $this->en()->get('/laboral/nominas?anio=2026&mes=11')->assertSee('María López Ruiz')->assertSee('Agustín Pérez');
        $this->en()->get('/laboral/nominas?anio=2026&mes=11&t='.$a->id)->assertOk()->assertSee('Trabajador en el RETA')->assertSee('Emitir nómina')->assertDontSee('Contingencias comunes');
        $this->en()->post('/laboral/nominas/'.$a->id.'/guardar', ['anio' => 2026, 'mes' => 11, 'accion' => 'guardar', 'nl' => ['concepto' => 'Retribución administrador', 'importe' => '1.000,00', 'clase' => 'salarial'], 'tipos' => ['irpf' => '15,00']])->assertRedirect();
        $n = app(Nomina::class)->nomina(Nomina::rellenar($a->fresh()->ficha(), $a->cliente), 2026, 11, Nomina::incidenciasAnio($a->cliente, 2026), 0);
        $this->assertSame(0.0, (float) $n['ssT']);
        $this->assertSame(0.0, (float) $n['ssE']);
        $this->assertSame(150.0, (float) $n['irpf']);
        $this->assertSame(850.0, (float) $n['liq']);
        $this->en()->get('/laboral/nominas/'.$a->id.'/recibo?anio=2026&mes=11')->assertOk()->assertSee('Retribución administrador');
        $this->en()->get('/laboral/seguros-sociales?anio=2026&mes=11')->assertDontSee('Agustín Pérez');
        $this->en()->get('/control-horario')->assertDontSee('Agustín Pérez');
        // Validación: documento y afiliación también para autónomos
        $this->en()->post('/laboral/trabajador/nuevo', ['regimen' => 'Autónomo', 'nom1' => 'X', 'nif' => '1', 'naf' => '211111111111', 'autAlta' => '2026-03-01'])->assertSessionHasErrors('ficha');
    }

    public function test_editor_de_nomina_borrador_avisos_emitir_y_recalcular(): void
    {
        $this->en()->post('/laboral/gestion/convenios', ['conv_nombre' => 'Convenio de hostelería de Huelva', 'conv_pagas' => '14',
            'cats' => [['cat' => 'Camarero', 'grupo' => '5', 'base' => '1.500,00', 'plus' => '0']]])->assertSessionHasNoErrors();
        $this->en()->post('/laboral/trabajador/nuevo', $this->ficha(['convCat' => 'Camarero']));
        $t = Trabajador::firstOrFail();
        $url = '/laboral/nominas?anio=2026&mes=11&t='.$t->id;
        $html = $this->en()->get($url)->assertOk()->assertSee('Calculada con la ficha y la tabla del convenio')->getContent();
        preg_match_all('/name="l\[(\d+)\]\[(id|cod|concepto|importe|clase|cobro)\]"(?: value="([^"]*)")?/', $html, $m, PREG_SET_ORDER);
        $l = [];
        foreach ($m as $x) {
            $l[$x[1]][$x[2]] = html_entity_decode($x[3] ?? '');
            $l[$x[1]]['activo'] = '1';
        }
        preg_match_all('/name="l\[(\d+)\]\[clase\]".*?<option value="(\w+)" selected/s', $html, $c, PREG_SET_ORDER);
        foreach ($c as $x) {
            $l[$x[1]]['clase'] = $x[2];
        }
        $sb = collect($l)->search(fn ($x) => $x['id'] === 'sb');
        $this->assertNotFalse($sb);
        $l[$sb]['importe'] = '1.600,00';
        $base = ['anio' => 2026, 'mes' => 11, 'l' => $l, 'tipos' => ['des_t' => '', 'irpf' => '2,00'], 'fecha_pago' => '2026-11-30'];
        // Guardar con un importe distinto del convenio y un concepto manual
        $this->en()->post('/laboral/nominas/'.$t->id.'/guardar', $base + ['accion' => 'guardar', 'nl' => ['concepto' => 'Plus festivo', 'importe' => '40,00', 'clase' => 'salarial'], 'nd' => ['concepto' => 'Embargo juzgado', 'importe' => '25,00']])->assertRedirect();
        $g = NominaMes::firstOrFail();
        $this->assertSame('Borrador', $g->estado);
        $this->assertSame(1600.0, (float) collect($g->datos['lineas'])->firstWhere('id', 'sb')['importe']);
        $this->en()->get($url)->assertOk()->assertSee('Borrador guardado')->assertSee('No es por convenio')->assertSee('Concepto añadido a mano');
        $this->en()->get('/laboral/nominas/'.$t->id.'/recibo?anio=2026&mes=11')->assertOk()->assertSee('Plus festivo')->assertSee('Embargo juzgado')->assertSee('Tributación I.R.P.F.')->assertSee('2,00');
        $n = app(Nomina::class)->nomina(Nomina::rellenar($t->ficha(), $t->cliente), 2026, 11, Nomina::incidenciasAnio($t->cliente, 2026), 0);
        $this->assertSame(2.0, (float) $n['ip']);
        $this->assertSame(25.0, (float) $n['ded']);
        // Emitir bloquea; reabrir desbloquea; recalcular borra el borrador
        $this->en()->post('/laboral/nominas/'.$t->id.'/guardar', $base + ['accion' => 'emitir'])->assertRedirect();
        $this->assertSame('Emitida', $g->fresh()->estado);
        $this->en()->post('/laboral/nominas/'.$t->id.'/guardar', $base + ['accion' => 'guardar'])->assertSessionHasErrors('ficha');
        // Reabrir exige motivo y deja la versión emitida en el historial
        $this->en()->post('/laboral/nominas/'.$t->id.'/guardar', ['anio' => 2026, 'mes' => 11, 'accion' => 'reabrir'])->assertSessionHasErrors('ficha');
        $this->assertSame('Emitida', $g->fresh()->estado);
        $this->en()->post('/laboral/nominas/'.$t->id.'/guardar', ['anio' => 2026, 'mes' => 11, 'accion' => 'reabrir', 'motivo' => 'Error en el plus festivo'])->assertRedirect();
        $this->assertSame('Borrador', $g->fresh()->estado);
        $this->assertSame('Error en el plus festivo', $g->fresh()->datos['historial'][0]['motivo']);
        $this->en()->get($url)->assertOk()->assertSee('Historial de rectificaciones (1)');
        $this->en()->post('/laboral/nominas/'.$t->id.'/guardar', ['anio' => 2026, 'mes' => 11, 'accion' => 'recalcular'])->assertRedirect();
        $this->assertSame(0, NominaMes::count());
        $this->en()->get($url)->assertOk()->assertSee('Calculada con la ficha y la tabla del convenio');
    }

    public function test_integral_recibos_fijos_embargos_y_variables_a_mano(): void
    {
        $this->assertSame(233.7, Nomina::embargable(2000, 1221));
        $this->assertSame(0.0, Nomina::embargable(1000, 1221));
        $this->en()->post('/laboral/trabajador/nuevo', $this->ficha());
        $t = Trabajador::firstOrFail();
        $svc = app(Nomina::class);
        $w = fn () => Nomina::rellenar($t->fresh()->ficha(), $t->cliente);
        $inc = fn () => Nomina::incidenciasAnio($t->cliente, 2026);
        // Concepto y deducción fijos + embargo de cantidad fija: se repiten el mes siguiente
        $auto = array_map(fn ($l) => ['id' => $l['id'], 'cod' => $l['cod'], 'concepto' => $l['concepto'], 'importe' => (string) $l['importe'], 'clase' => $l['clase'], 'cobro' => $l['cobro'] ?? '', 'activo' => '1'],
            Nomina::conceptos($w(), 11, [], null, 2026)['lineas']);
        $this->en()->post('/laboral/nominas/'.$t->id.'/guardar', ['anio' => 2026, 'mes' => 11, 'accion' => 'guardar', 'l' => $auto,
            'nl' => [['concepto' => 'Plus de transporte', 'importe' => '60,00', 'clase' => 'salarial', 'fijo' => '1'], ['concepto' => 'Horas complementarias', 'cant' => '4', 'precio' => '12,50', 'clase' => 'salarial']],
            'nd' => [['concepto' => 'Cuota sindical', 'importe' => '10,00', 'fijo' => '1']],
            'emb_new' => ['organismo' => 'Juzgado', 'expediente' => '123/2026', 'total' => '300,00', 'modo' => 'fijo', 'cuota' => '200,00']])->assertSessionHasNoErrors();
        $this->assertCount(1, $t->fresh()->datos['embargos']);
        Nomina::olvidarGuardadas();
        $nov = $svc->nomina($w(), 2026, 11, $inc(), 0);
        $this->assertSame(50.0, (float) collect($nov['lineas'])->firstWhere('concepto', 'Horas complementarias')['importe']);
        $this->assertSame(210.0, (float) $nov['ded']);
        $dic = $svc->nomina($w(), 2026, 12, $inc(), 0);
        $this->assertNotNull(collect($dic['lineas'])->firstWhere('concepto', 'Plus de transporte'));
        $this->assertNull(collect($dic['lineas'])->firstWhere('concepto', 'Horas complementarias'));
        $this->assertSame(110.0, (float) $dic['ded']); // 10 de cuota sindical + 100 pendientes del embargo
        // La ficha no borra los fijos ni los embargos
        $this->en()->post('/laboral/trabajador/'.$t->id, $this->ficha(['nom1' => 'María']));
        $this->assertCount(1, $t->fresh()->datos['concFijos'] ?? []);
        $this->assertCount(1, $t->fresh()->datos['embargos'] ?? []);
        // Paga extra en recibo aparte: sale de la nómina de junio y se suma en los totales del mes
        $this->assertNotNull(collect($svc->nomina($w(), 2026, 6, $inc(), 0)['lineas'])->firstWhere('id', 'pv'));
        $this->en()->get('/laboral/nominas?anio=2026&mes=6&t='.$t->id.'&recibo=Extra')->assertOk()->assertSee('Este recibo todavía no existe');
        $this->en()->post('/laboral/nominas/'.$t->id.'/guardar', ['anio' => 2026, 'mes' => 6, 'recibo' => 'Extra', 'accion' => 'guardar',
            'l' => [['id' => 'pv', 'cod' => '90', 'concepto' => 'Paga de verano', 'importe' => '1.500,00', 'clase' => 'extra', 'cobro' => 'en_su_mes', 'activo' => '1']]])->assertSessionHasNoErrors();
        Nomina::olvidarGuardadas();
        $this->assertNull(collect($svc->nomina($w(), 2026, 6, $inc(), 0)['lineas'])->firstWhere('id', 'pv'));
        $ex = $svc->nomina($w(), 2026, 6, $inc(), 0, 'Extra');
        $this->assertSame(0.0, (float) $ex['ssT']);
        $this->assertGreaterThan(0, $ex['irpf']);
        $todo = $svc->recibosMes($w(), 2026, 6, $inc(), 0);
        $this->assertSame(round($todo['n']['liq'] + $ex['liq'], 2), $todo['t']['liq']);
        foreach ([1, 2, 3, 4] as $m) {
            $this->en()->get('/laboral/nominas/'.$t->id.'/recibo?anio=2026&mes=6&recibo=Extra&modelo='.$m)->assertOk()->assertSee($m === 4 ? 'PAGA DE VERANO' : 'Paga de verano');
        }
        $this->en()->get('/laboral/nominas/'.$t->id.'/recibo?anio=2026&mes=6&modelo=4')->assertOk()->assertSee('RECIBO INDIVIDUAL JUSTIFICATIVO DEL PAGO DE SALARIO')->assertSee('Percepciones salariales');
        // Finiquito propuesto con vacaciones no disfrutadas
        $this->en()->get('/laboral/nominas?anio=2026&mes=11&t='.$t->id.'&recibo=Finiquito')->assertOk()->assertSee('Vacaciones no disfrutadas');
        // Variables escritas a mano
        $this->en()->post('/laboral/nominas/'.$t->id.'/variables', ['anio' => 2026, 'mes' => 10, 'vl' => [['concepto' => 'Plus de nocturnidad', 'clase' => 'salarial', 'importe' => '45,00'], ['concepto' => '', 'importe' => '']]])->assertRedirect();
        $oct = $svc->nomina($w(), 2026, 10, $inc(), 0);
        $l = collect($oct['lineas'])->firstWhere('concepto', 'Plus de nocturnidad');
        $this->assertSame(45.0, (float) $l['importe']);
        $this->assertSame('07', $l['cod']);
    }
}
