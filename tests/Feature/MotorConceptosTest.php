<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Convenio;
use App\Models\ConvenioConcepto;
use App\Models\ConvenioVersion;
use App\Models\EmpresaConvenio;
use App\Models\NominaMes;
use App\Models\User;
use App\Services\Formula;
use App\Services\Nomina;
use App\Support\Dec;
use App\Support\DivisionPorCero;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Tests\TestCase;

/** Fase 4 laboral: decimales exactos, fórmulas cerradas con dependencias y ciclos, límites y explicación de cada línea */
class MotorConceptosTest extends TestCase
{
    use RefreshDatabase;

    private Cliente $a;

    private User $as;

    private ConvenioVersion $v;

    private array $base = ['naturaleza' => 'salarial', 'calculo' => 'fijo', 'unidad' => 'mes', 'cotiza' => 'si', 'tributa' => 'si', 'redondeo' => '2d', 'automatico' => '1', 'prop_jornada' => '1', 'prop_periodo' => '1'];

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
        $this->sec('categorias', ['cats' => [
            ['nombre' => 'Oficial de primera', 'grupo_cotizacion' => '8', 'unidad' => 'mes', 'salario_base' => '1.600,00', 'plus' => '120,00'],
            ['nombre' => 'Sin tabla', 'grupo_cotizacion' => '7', 'unidad' => 'mes', 'salario_base' => ''],
        ]])->assertSessionHasNoErrors();
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

    private function lineas(array $extra = []): Collection
    {
        $this->v->update(['estado' => 'aprobado', 'efectos_desde' => '2026-01-01']);
        $w = Nomina::rellenar(array_merge(['id' => 1, 'contrato' => Nomina::CONTRATOS[0], 'grupo' => 8, 'salBase' => 1600, 'pagas' => 14, 'jor' => 100, 'alta' => '2026-01-01', 'convCat' => 'Oficial de primera'], $extra), $this->a);

        return collect(Nomina::conceptos($w, 11, [], null, 2026)['lineas'])->keyBy('concepto');
    }

    public function test_decimales_exactos_y_redondeo_la_mitad_hacia_arriba(): void
    {
        // 0,1 + 0,2 en coma flotante no es 0,3; con Dec, sí
        $this->assertTrue(Dec::de('0,1')->mas('0,2')->menos('0,3')->esCero());
        $this->assertSame('0,01', Dec::de('0,005')->txt());
        $this->assertSame('−0,01', Dec::de('-0,005')->txt());
        $this->assertSame('80,00', Dec::de('79,5')->redondear(0)->txt());
        $this->assertSame('1.234,57', Dec::de('1.234,565')->txt());
        $this->assertSame(79.63, Dec::de(1365)->entre(30)->por('1,75')->redondear()->float());
        // Producto grande sin desbordar 64 bits
        $this->assertSame('123.456.789,00', Dec::de('123456789')->por(1)->txt());
        $this->expectException(DivisionPorCero::class);
        Dec::de(1)->entre(0);
    }

    public function test_formulas_validadas_sin_ejecutar_codigo(): void
    {
        $this->assertNull(Formula::validar('salario_base / horas_mes * 1,25'));
        $this->assertNull(Formula::validar('max(salario_dia * 0,1; 2) + c_pt', ['PT']));
        $this->assertNull(Formula::validar('redondear(salario_base * 0,07; 2)'));
        $this->assertStringContainsString('Variable no permitida', Formula::validar('sueldo * 2'));
        $this->assertStringContainsString('Función no permitida', Formula::validar('system(1)'));
        $this->assertStringContainsString('Carácter no permitido', Formula::validar('salario_base; DROP TABLE users'.'$'));
        $this->assertStringContainsString('Carácter no permitido', Formula::validar('`ls`'));
        $this->assertStringContainsString('código NOC', Formula::validar('c_NOC * 2'));
        $this->assertSame('La fórmula divide por cero.', Formula::validar('salario_base / (jornada - jornada)'));
        $this->assertSame('Falta cerrar un paréntesis.', Formula::validar('(salario_base * 2'));
        $this->assertStringContainsString('dos valores', Formula::validar('min(salario_base)'));
        // Un dato que falta no se convierte en cero
        $this->expectException(InvalidArgumentException::class);
        Formula::evaluar('horas_mes * 2', ['horas_mes' => null]);
    }

    public function test_orden_de_dependencias_y_ciclos(): void
    {
        [$orden, $err] = Formula::orden(['B' => 'c_C + c_A', 'C' => 'c_A / 4', 'A' => 'salario_base * 0,1']);
        $this->assertNull($err);
        $this->assertSame(['A', 'C', 'B'], $orden);
        [, $err] = Formula::orden(['X' => 'c_Y + 1', 'Y' => 'c_Z', 'Z' => 'c_X * 2']);
        $this->assertStringContainsString('en círculo', $err);
        $this->assertStringContainsString('X → Y → Z → X', $err);
    }

    public function test_el_editor_rechaza_formulas_invalidas_y_ciclos(): void
    {
        $this->sec('conceptos', ['conceptos' => [
            array_merge($this->base, ['nombre' => 'Plus A', 'codigo' => 'A', 'calculo' => 'formula', 'formula' => 'c_B + 1']),
            array_merge($this->base, ['nombre' => 'Plus B', 'codigo' => 'B', 'calculo' => 'formula', 'formula' => 'c_A * 2']),
            array_merge($this->base, ['nombre' => 'Plus C', 'codigo' => 'C', 'calculo' => 'formula', 'formula' => 'sueldo * 2']),
            array_merge($this->base, ['nombre' => 'Plus D', 'codigo' => 'c', 'valor' => '10']),
        ]])->assertSessionHasErrors(['conceptos.0.formula', 'conceptos.1.formula', 'conceptos.2.formula', 'conceptos.3.codigo']);
        $this->assertSame(0, ConvenioConcepto::where('nombre', 'like', 'Plus _')->count());
    }

    public function test_formulas_que_usan_otros_conceptos_en_su_orden_y_explicadas(): void
    {
        // Se guardan en orden «equivocado»: B depende de C, que depende del plus de transporte
        $this->sec('conceptos', ['conceptos' => [
            array_merge($this->base, ['nombre' => 'Plus B', 'codigo' => 'B', 'calculo' => 'formula', 'formula' => 'c_PT * 2 + c_C']),
            array_merge($this->base, ['nombre' => 'Plus C', 'codigo' => 'C', 'calculo' => 'formula', 'formula' => 'c_PT / 4']),
            array_merge($this->base, ['nombre' => 'Plus de transporte', 'codigo' => 'PT', 'valor' => '100,00', 'referencia' => 'art. 40']),
        ]])->assertSessionHasNoErrors();
        $L = $this->lineas();
        $this->assertSame(100.0, $L['Plus de transporte']['importe']);
        $this->assertSame(25.0, $L['Plus C']['importe']);
        $this->assertSame(225.0, $L['Plus B']['importe']);
        $this->assertStringContainsString('fórmula «c_PT * 2 + c_C»', $L['Plus B']['expl']);
        $this->assertStringContainsString('c_PT = 100,0000', $L['Plus B']['expl']);
        $this->assertStringContainsString('art. 40', $L['Plus de transporte']['regla']);
        // El salario base también explica de dónde sale
        $this->assertSame('1.600,00 €/mes (mes completo)', $L['Salario base mensual']['expl']);
        $this->assertStringContainsString('categoría Oficial de primera', $L['Salario base mensual']['regla']);
        // Media jornada y alta el día 16: proporcional a la jornada y a los días
        $L2 = $this->lineas(['jor' => 50, 'alta' => '2026-11-16', 'salBase' => 800]);
        $this->assertSame(Dec::de(100)->por('0,5')->por(Dec::de(15)->entre(30))->redondear()->float(), $L2['Plus de transporte']['importe']);
        $this->assertStringContainsString('50 % de jornada', $L2['Plus de transporte']['expl']);
    }

    public function test_limite_de_cotizacion_e_irpf_se_parte_en_dos_lineas(): void
    {
        $this->sec('conceptos', ['conceptos' => [
            array_merge($this->base, ['nombre' => 'Dietas', 'naturaleza' => 'extrasalarial', 'unidad' => 'dia_trabajado', 'valor' => '30,00', 'prop_jornada' => '0',
                'cotiza' => 'limite', 'tributa' => 'limite', 'limite' => 'art. 9 RIRPF', 'limite_importe' => '26,67', 'limite_unidad' => 'dia']),
        ]])->assertSessionHasNoErrors();
        $L = $this->lineas();
        $d = Nomina::diasLaborables(2026, 11);
        $dentro = $L['Dietas (hasta el límite)'];
        $fuera = $L['Dietas (exceso sobre el límite)'];
        $this->assertSame(Dec::de('26,67')->por($d)->float(), $dentro['importe']);
        $this->assertSame('no_salarial', $dentro['clase']);
        $this->assertSame(Dec::de(30)->por($d)->menos(Dec::de('26,67')->por($d))->float(), $fuera['importe']);
        $this->assertSame('salarial', $fuera['clase']);
        $this->assertSame($d * 30.0, round($dentro['importe'] + $fuera['importe'], 2));
    }

    public function test_simulador_sin_guardar_nada(): void
    {
        $antes = [ConvenioConcepto::count(), NominaMes::count()];
        $r = $this->actingAs($this->as)->withSession(['cliente_activo' => $this->a->id])
            ->get(route('laboral.convenios', ['sim' => ['cat' => 'Oficial de primera', 'mes' => '2026-11', 'jor' => '50', 'alta' => '2026-11-11']]));
        // 1.600 × 50 % = 800 €/mes; del 11 al 30: 20 días → 800 × 20 / 30 = 533,33
        $r->assertOk()->assertSee('Simular una nómina con este convenio')->assertSee('533,33 €')->assertSee('20 de 30 días')->assertSee('Base de contingencias comunes');
        $this->assertSame($antes, [ConvenioConcepto::count(), NominaMes::count()]);
        // Sin salario en la tabla no se inventa
        $this->get(route('laboral.convenios', ['sim' => ['cat' => 'Sin tabla', 'mes' => '2026-11', 'jor' => '100']]))
            ->assertSee('no tiene salario base en la tabla');
        $this->get(route('laboral.convenios', ['sim' => ['cat' => 'Oficial de primera', 'mes' => '2026-11', 'jor' => '100', 'alta' => '2026-11-20', 'baja' => '2026-11-10']]))
            ->assertSee('La baja no puede ser anterior al alta');
    }
}
