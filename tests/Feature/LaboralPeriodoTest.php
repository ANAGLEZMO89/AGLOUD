<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Trabajador;
use App\Models\User;
use App\Services\Nomina;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Fase 1 laboral: periodo retribuido por alta y baja, categoría real del convenio, origen del cálculo y explicación de bases */
class LaboralPeriodoTest extends TestCase
{
    use RefreshDatabase;

    private Cliente $a;

    private User $as;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-13 10:00:00');
        CarbonImmutable::setTestNow('2026-11-13 10:00:00');
        $this->a = $this->cliente('FILIP PRUEBA', 'Z5585564L', ['tipo' => 'Autónomo']);
        $this->as = $this->usuario(User::ASESOR);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function w(array $o = []): array
    {
        return Nomina::rellenar(array_merge(['id' => 1, 'contrato' => Nomina::CONTRATOS[0], 'grupo' => 10, 'salTipo' => 'Diario', 'salDia' => 40.55, 'pagas' => 14, 'jor' => 100, 'alta' => '2026-11-10'], $o), $this->a);
    }

    private function sb(array $doc): array
    {
        return collect($doc['lineas'])->firstWhere('id', 'sb');
    }

    public function test_alta_a_mitad_de_mes_con_salario_diario(): void
    {
        $w = $this->w();
        $this->assertSame(['2026-11-10', '2026-11-30', 21, 30], Nomina::periodo($w, 2026, 11));
        $sb = $this->sb(Nomina::conceptos($w, 11, [], null, 2026));
        $this->assertSame([21, 40.55, 851.55], [$sb['cant'], $sb['precio'], $sb['importe']]);
        // Mes completo siguiente: 31 días de diciembre
        $this->assertSame(31 * 40.55, $this->sb(Nomina::conceptos($w, 12, [], null, 2026))['importe']);
        // Antes del alta no hay nada que pagar
        $this->assertSame(0, Nomina::periodo($w, 2026, 10)[2]);
    }

    public function test_meses_de_28_29_30_y_31_dias_y_baja_a_mitad_de_mes(): void
    {
        $w = $this->w(['alta' => '2026-01-01']);
        foreach ([[2027, 2, 28], [2028, 2, 29], [2026, 4, 30], [2026, 5, 31]] as [$y, $m, $d]) {
            $this->assertSame(round($d * 40.55, 2), $this->sb(Nomina::conceptos($w, $m, [], null, $y))['importe'], "$m/$y");
        }
        // Mensual: mes completo = salario íntegro aunque tenga 31 días; alta el día 10 = 21/30
        $m = $this->w(['salTipo' => 'Mensual', 'salBase' => 1500, 'grupo' => 5, 'alta' => '2026-01-01']);
        $this->assertSame(1500.0, (float) $this->sb(Nomina::conceptos($m, 5, [], null, 2026))['importe']);
        $this->assertSame(1050.0, (float) $this->sb(Nomina::conceptos(array_merge($m, ['alta' => '2026-11-10']), 11, [], null, 2026))['importe']);
        // Baja el 15: del 1 al 15
        $b = array_merge($m, ['baja' => '2026-11-15']);
        $this->assertSame(['2026-11-01', '2026-11-15', 15, 30], Nomina::periodo($b, 2026, 11));
        $this->assertSame(750.0, (float) $this->sb(Nomina::conceptos($b, 11, [], null, 2026))['importe']);
    }

    public function test_base_minima_prorrateada_y_explicada(): void
    {
        $n = Nomina::calcular($this->w(), 11, [], 0);
        // Bruto computable: 851,55 + prorrata (2 pagas de 30 días × 40,55 / 12 × 21/30 = 141,93) = 993,48
        // Base mínima del grupo 10: 47,48 €/día × 21 días = 997,08 → cotiza por la mínima
        $this->assertEqualsWithDelta(141.93, $n['prorB'], 0.01);
        $this->assertEqualsWithDelta(997.08, $n['base'], 0.001);
        $txt = implode(' ', $n['baseExpl']);
        $this->assertStringContainsString('base mínima del grupo 10', $txt);
        $this->assertStringContainsString('21 días', $txt);
        $this->assertStringContainsString('pendientes de contrastar', $txt);
    }

    public function test_convenio_sin_categorias_no_da_falsos_positivos(): void
    {
        $en = fn () => $this->actingAs($this->as)->withSession(['cliente_activo' => $this->a->id]);
        $en()->post('/laboral/gestion/convenios', ['_enviado' => 1, 'conv_nombre' => 'Convenio de la construcción', 'conv_jornada' => '1736', 'conv_vacaciones' => '30', 'conv_pagas' => '14'])->assertSessionHasNoErrors();
        // Trabajador guardado con el texto de relleno antiguo como «categoría»
        $t = Trabajador::create(['cliente_id' => $this->a->id, 'estado' => 'Alta', 'alta' => '2026-11-10', 'contrato' => '100', 'datos' => array_merge($this->w(), [
            'nombre' => 'Beka Prueba', 'nom1' => 'Beka', 'ape1' => 'Prueba', 'nif' => 'X1234567L', 'tipoDoc' => 'NIE', 'naf' => '211234567890', 'ccc' => '03149921012', 'convCat' => 'Sin categorías en el convenio'])]);
        $w = Nomina::rellenar($t->ficha(), $this->a);
        $this->assertSame('', $w['convCat']);
        $leg = collect(Nomina::comprobar($w, Nomina::config($this->a), 1, '2026-11-13'));
        $this->assertFalse($leg->contains(fn ($x) => str_contains($x['l'], 'Categoría del convenio asignada')));
        $this->assertSame('aviso', $leg->first(fn ($x) => str_contains($x['l'], 'no tiene tablas salariales'))['nivel']);
        $en()->get('/laboral/trabajador/'.$t->id.'?sec=contrato')->assertOk()->assertDontSee('Todo correcto')->assertDontSee('Sin incidencias')
            ->assertSee('— El convenio no tiene categorías —');
        // El origen del cálculo dice qué se ha usado de verdad
        $en()->get('/laboral/nominas?anio=2026&mes=11&t='.$t->id)->assertOk()->assertDontSee('Calculada desde el convenio')
            ->assertSee('Calculada con la ficha (el convenio no tiene tablas salariales)')->assertSee('10/11/2026 - 30/11/2026')->assertSee('21 de 30')
            ->assertSee('Cómo se ha calculado la base de cotización');
        // El recibo muestra el periodo real
        $en()->get('/laboral/nominas/'.$t->id.'/recibo?anio=2026&mes=11')->assertOk()->assertSee('10/11/2026 a 30/11/2026')->assertSee('851,55');
        // Estado del convenio: identificado pero sin tablas
        $est = collect(Nomina::estadoConvenio(Nomina::config($this->a)['conv']))->keyBy(0);
        $this->assertSame('falta', $est['Tablas salariales'][1]);
        $this->assertSame('pendiente', $est['Identificación'][1]);
        $en()->get('/laboral/gestion/convenios')->assertSee('Estado del convenio')->assertSee('Sin categorías: las nóminas no se pueden calcular ni comprobar con el convenio');
        $en()->get('/laboral')->assertSee('no tiene tablas salariales');
    }

    public function test_categoria_inexistente_o_vacia_es_error(): void
    {
        $P = Nomina::config($this->a);
        $P['conv']['cats'] = [['cat' => 'Peón', 'grupo' => '10', 'base' => 45.0, 'plus' => 0, 'unidad' => 'dia']];
        $chk = fn ($cat, $sd = 40.55) => collect(Nomina::comprobar($this->w(['convCat' => $cat, 'salDia' => $sd]), $P, 1, '2026-11-13'));
        $this->assertSame('error', $chk('')->first(fn ($x) => str_contains($x['l'], 'Falta la categoría'))['nivel']);
        $this->assertSame('error', $chk('Oficial')->first(fn ($x) => str_contains($x['l'], 'no existe en el convenio'))['nivel']);
        $this->assertFalse($chk('Peón')->first(fn ($x) => str_contains($x['l'], 'Salario base igual o superior'))['ok']);
        $this->assertTrue($chk('Peón', 45.0)->first(fn ($x) => str_contains($x['l'], 'Salario base igual o superior'))['ok']);
    }
}
