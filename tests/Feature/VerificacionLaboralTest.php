<?php

namespace Tests\Feature;

use App\Models\Apunte;
use App\Models\Asiento;
use App\Models\Cliente;
use App\Models\Convenio;
use App\Models\ConvenioCategoria;
use App\Models\ConvenioConcepto;
use App\Models\ConvenioVersion;
use App\Models\EmpresaConvenio;
use App\Models\NominaMes;
use App\Models\Trabajador;
use App\Models\User;
use App\Services\ConvenioIA;
use App\Services\Convenios;
use App\Services\Nomina;
use App\Support\Formato;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Fase 9 laboral: verificación de los casos de la especificación que no tenían prueba propia
 * (3 salario diario y mensual, 5 convenio sin categorías, 10 antigüedad por tramos, 11 ajustes de bases,
 * 14 importación de IA incompleta, contradictoria o duplicada, 18 coincidencia entre cálculo, recibo y exportaciones).
 */
class VerificacionLaboralTest extends TestCase
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
        $this->v = ConvenioVersion::create(['convenio_id' => $cv->id, 'nombre' => '2026', 'estado' => 'aprobado', 'efectos_desde' => '2020-01-01', 'reglas' => ['jornada' => '1800']]);
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
            'pagas' => 14, 'jor' => 100, 'horas' => '40', 'alta' => '2020-01-01', 'iban' => 'ES9121000418450200051332'], $d);

        return Trabajador::create(['cliente_id' => $this->a->id, 'estado' => 'Alta', 'alta' => $d['alta'], 'contrato' => '100', 'datos' => $d]);
    }

    private function doc(Trabajador $t, int $m, array $extra = []): array
    {
        Nomina::olvidarGuardadas();

        return Nomina::conceptos(array_merge(Nomina::rellenar($t->fresh()->ficha(), $this->a), $extra), $m, [], null, 2026);
    }

    private function sb(array $doc): float
    {
        return (float) collect($doc['lineas'])->firstWhere('id', 'sb')['importe'];
    }

    /** Caso 3: salario diario (por día natural) frente a mensual (mes comercial de 30 días) */
    public function test_caso_3_salario_diario_y_mensual(): void
    {
        $m = $this->trab();
        $d = $this->trab(['nombre' => 'Luis Gil Ros', 'nif' => '00000000T', 'salTipo' => 'Diario', 'salDia' => 50, 'salBase' => 0, 'diasExtra' => 30]);
        foreach ([2 => 28, 4 => 30, 12 => 31] as $mes => $dias) {
            $this->assertSame(1500.0, $this->sb($this->doc($m, $mes)), "mensual en el mes $mes");
            $this->assertSame($dias * 50.0, $this->sb($this->doc($d, $mes)), "diario en el mes $mes");
        }
        // Paga extra del de salario diario: los días que fija la ficha (30 × 50); la del mensual, una mensualidad
        $this->assertSame(1500.0, (float) collect($this->doc($d, 12)['lineas'])->firstWhere('id', 'pn')['importe']);
        // Alta el día 10: el diario cobra los días naturales; el mensual, los días / 30
        $this->assertSame(21 * 50.0, $this->sb($this->doc($d, 11, ['alta' => '2026-11-10'])));
        $this->assertSame(round(1500 * 21 / 30, 2), $this->sb($this->doc($m, 11, ['alta' => '2026-11-10'])));
    }

    /** Caso 5: convenio sin categorías: la nómina sale de la ficha, se dice así y no se inventa nada */
    public function test_caso_5_convenio_sin_categorias(): void
    {
        $t = $this->trab(['convCat' => '']);
        $doc = $this->doc($t, 11);
        $sb = collect($doc['lineas'])->firstWhere('id', 'sb');
        $this->assertSame([1500.0, 'ficha', null], [(float) $sb['importe'], $sb['origen'], $sb['conv']]);
        $this->assertStringContainsString('sin categoría del convenio', $sb['regla']);
        $est = collect(Nomina::estadoConvenio(Convenios::forma(Convenio::first(), $this->v)))->keyBy(0);
        $this->assertSame('falta', $est['Tablas salariales'][1]);
        // El simulador no puede simular sin tabla
        $this->en()->get(route('laboral.convenios', ['sim' => ['cat' => 'Peón', 'mes' => '2026-11', 'jor' => '100']]))->assertOk()->assertSee('Elige una categoría de la tabla salarial');
        $this->en()->get(route('laboral.integral', ['anio' => 2026, 'mes' => 11, 't' => $t->id]))->assertOk()->assertSee('Calculada con la ficha (el convenio no tiene tablas salariales)');
    }

    /** Caso 10: antigüedad por tramos (trienios con tope, porcentaje sobre base + plus, congelada) */
    public function test_caso_10_antiguedad_por_tramos(): void
    {
        // Trienios de 20 € con tope de 2: alta 15/11/2017 → 3 trienios en noviembre de 2026 (9 años), con tope 2 → 40 €
        Convenios::guardarReglas($this->v, ['antig' => ['periodo' => 3, 'tipo' => 'importe', 'valor' => 20, 'tope' => 2, 'base' => 'salario_base', 'art' => 'art. 48']]);
        $t = $this->trab(['alta' => '2017-11-15']);
        $ant = collect($this->doc($t, 11)['lineas'])->firstWhere('id', 'ant');
        $this->assertSame([40.0, 'Antigüedad (2 trienios)'], [(float) $ant['importe'], $ant['concepto']]);
        // Sin tope: el tercer trienio se cumple en noviembre (15/11/2026), no en octubre
        Convenios::guardarReglas($this->v, ['antig' => ['periodo' => 3, 'tipo' => 'importe', 'valor' => 20, 'tope' => null, 'base' => 'salario_base']]);
        $this->assertSame(40.0, (float) collect($this->doc($t, 10)['lineas'])->firstWhere('id', 'ant')['importe']);
        $this->assertSame(60.0, (float) collect($this->doc($t, 11)['lineas'])->firstWhere('id', 'ant')['importe']);
        // Quinquenios del 5 % sobre salario base + plus: 1 quinquenio × 5 % × (1.500 + 100) = 80 €
        Convenios::guardarReglas($this->v, ['antig' => ['periodo' => 5, 'tipo' => 'porcentaje', 'valor' => 5, 'tope' => null, 'base' => 'salario_base_plus']]);
        $t2 = $this->trab(['nombre' => 'Luis Gil Ros', 'nif' => '00000000T', 'alta' => '2020-03-01', 'plus' => 100]);
        $this->assertSame([80.0, 1], array_slice(Nomina::antiguedad(Nomina::paraPeriodo(Nomina::rellenar($t2->ficha(), $this->a), 2026, 11), 2026, 11), 0, 2));
        // Congelada desde 2019: no genera periodos nuevos (2017 → 2019 = 0 trienios completos)
        Convenios::guardarReglas($this->v, ['antig' => ['periodo' => 3, 'tipo' => 'importe', 'valor' => 20, 'tope' => null, 'base' => 'salario_base', 'congelada' => '2019-12-31']]);
        $this->assertNull(collect($this->doc($t, 11)['lineas'])->firstWhere('id', 'ant'));
        // Fecha de antigüedad anterior al alta (subrogación) cuenta desde esa fecha
        Convenios::guardarReglas($this->v, ['antig' => ['periodo' => 3, 'tipo' => 'importe', 'valor' => 20, 'tope' => null, 'base' => 'salario_base', 'congelada' => '']]);
        $t3 = $this->trab(['nombre' => 'Eva Ruiz Sanz', 'nif' => '11111111H', 'alta' => '2025-01-01', 'fAntig' => '2019-01-01']);
        $this->assertSame(40.0, (float) collect($this->doc($t3, 11)['lineas'])->firstWhere('id', 'ant')['importe']);
    }

    /** Caso 11: ajustes de la base de cotización con su explicación (mínima, máxima, días de IT) */
    public function test_caso_11_ajustes_de_bases_explicados(): void
    {
        $w = Nomina::rellenar(['id' => 0, 'contrato' => Nomina::CONTRATOS[0], 'grupo' => 1, 'salBase' => 900, 'pagas' => 14, 'jor' => 100, 'alta' => '2020-01-01', 'prorr' => true], $this->a);
        $n = Nomina::calcular($w, 11, [], 0);
        $this->assertSame(1989.30, (float) $n['base']);
        $this->assertStringContainsString('inferior a la base mínima del grupo 1', implode(' ', $n['baseExpl']));
        $alto = Nomina::calcular(array_merge($w, ['salBase' => 7000, 'grupo' => 3]), 11, [], 0);
        $this->assertSame(5101.20, (float) $alto['base']);
        $this->assertStringContainsString('Supera la base máxima', implode(' ', $alto['baseExpl']));
        $this->assertStringContainsString('Orden PJC/297/2026', implode(' ', $alto['baseExpl']));
        // Ni mínima ni máxima: la suma explicada coincide con la base
        $med = Nomina::calcular(array_merge($w, ['salBase' => 1800, 'grupo' => 3]), 11, [], 0);
        $this->assertSame(round(1800 + 1800 * 2 / 12, 2), (float) $med['base']);
        $this->assertStringContainsString(Formato::eur((float) $med['base']), $med['baseExpl'][0]);
    }

    /** Caso 14: importación de IA incompleta, contradictoria o duplicada */
    public function test_caso_14_importacion_ia_incompleta_contradictoria_o_duplicada(): void
    {
        $base = ['nombre' => 'Convenio X', 'codigo' => '21000000011999', 'ambito' => 'Huelva', 'vigencia' => '2026', 'anio_tablas' => 2026, 'jornada_anual' => 1800, 'vacaciones_dias' => 30,
            'tablas' => [['categoria' => 'Peón', 'grupo_cotizacion' => 10, 'salario_base' => 50, 'unidad' => 'dia', 'plus_convenio' => 0, 'articulo' => 'Anexo I']],
            'pagas_extra' => [], 'antiguedad' => ['existe' => false], 'pluses' => [['nombre' => 'Plus de transporte', 'unidad' => 'mes', 'tipo' => 'importe', 'valor' => 60, 'automatico' => true, 'cotiza' => true, 'tributa' => true, 'articulo' => 'art. 9']],
            'it' => ['contingencias_comunes' => [], 'accidente_trabajo' => [], 'articulo' => ''], 'horas_extra' => ['tipo' => 'no_consta', 'valor' => null, 'articulo' => ''], 'avisos' => []];
        // Incompleta: sin tablas → no se guarda nada
        $this->en()->post(route('laboral.convenios.ia.pegar'), ['respuesta' => json_encode(array_merge($base, ['tablas' => []]))])->assertSessionHasErrors('conv');
        $this->assertNull(Nomina::config($this->a)['convPropuesta'] ?? null);
        // Incompleta: lo que falta queda «no localizado» y no se convierte en cero
        $p = ConvenioIA::normalizar(array_merge($base, ['tablas' => array_merge($base['tablas'], [['categoria' => 'Oficial', 'grupo_cotizacion' => 8, 'salario_base' => null, 'unidad' => 'mes']])]));
        $this->assertSame(['Peón'], array_column($p['cats'], 'cat'));
        $this->assertSame(['no_localizado', 'no_localizado'], [$p['estados']['pagas_extra'], $p['estados']['horas_extra']]);
        $this->assertNull($p['hx']);
        // Contradictoria: dos salarios para la misma categoría
        $c = ConvenioIA::normalizar(array_merge($base, ['tablas' => array_merge($base['tablas'], [['categoria' => 'Peón', 'grupo_cotizacion' => 10, 'salario_base' => 55, 'unidad' => 'dia']])]));
        $this->assertCount(1, $c['cats']);
        $this->assertTrue(collect($c['revision'])->contains(fn ($x) => $x[0] === 'contradictorio'));
        // Duplicada: pegar y aplicar dos veces la misma lectura no duplica categorías ni conceptos
        foreach ([1, 2] as $i) {
            $this->en()->post(route('laboral.convenios.ia.pegar'), ['respuesta' => json_encode($base)])->assertSessionHas('ok');
            $this->en()->post(route('laboral.convenios.propuesta'), ['accion' => 'aplicar', 'destino' => 'nueva', 'nueva_nombre' => 'Lectura IA', 'nueva_efectos' => '2026-01-01',
                'apartados' => ['tablas', 'pluses'], 'cats' => [['orig' => 'Peón', 'incluir' => '1', 'cat' => 'Peón', 'grupo' => '10', 'base' => '50,00', 'unidad' => 'dia', 'plus' => '0']]])->assertSessionHasNoErrors();
            $v = ConvenioVersion::where('nombre', 'Lectura IA')->latest('id')->first();
            if ($i === 2) {
                // La segunda vez, a la misma versión (borrador): sigue habiendo una sola categoría y un solo plus
                $this->en()->post(route('laboral.convenios.ia.pegar'), ['respuesta' => json_encode($base)]);
                $this->en()->post(route('laboral.convenios.propuesta'), ['accion' => 'aplicar', 'destino' => 'actual', 'version_id' => $v->id, 'apartados' => ['tablas', 'pluses'],
                    'cats' => [['orig' => 'Peón', 'incluir' => '1', 'cat' => 'Peón', 'grupo' => '10', 'base' => '50,00', 'unidad' => 'dia', 'plus' => '0']]])->assertSessionHasNoErrors();
                $this->assertSame(1, ConvenioCategoria::where('version_id', $v->id)->where('nombre', 'Peón')->count());
                $this->assertSame(1, ConvenioConcepto::where('version_id', $v->id)->where('nombre', 'Plus de transporte')->count());
            }
        }
        // La aprobada no se ha tocado
        $this->assertSame(0, ConvenioCategoria::where('version_id', $this->v->id)->count());
    }

    /** Caso 18: el mismo importe en el cálculo, el editor, el recibo, las exportaciones, la remesa, los seguros sociales y el asiento */
    public function test_caso_18_coincidencia_entre_calculo_recibo_y_exportaciones(): void
    {
        $this->a->setSeccion('bancos', [['iban' => 'ES9121000418450200051332', 'bic' => 'CAIXESBBXXX', 'entidad' => 'CaixaBank', 'principal' => true]]);
        $this->a->save();
        $t = $this->trab(['prorr' => true, 'irpfModo' => 'fijo', 'irpf' => 10, 'plus' => 123.45]);
        $this->en()->post(route('laboral.emitir.mes'), ['anio' => 2026, 'mes' => 11])->assertSessionHasNoErrors();
        $calc = NominaMes::firstOrFail()->datos['calc'];
        [$dev, $liq, $base, $irpf] = array_map(fn ($k) => Formato::num((float) $calc[$k]), ['dev', 'liq', 'base', 'irpf']);
        // Recibo (lo que se imprime en PDF)
        $this->en()->get(route('laboral.recibo', [$t->id, 'anio' => 2026, 'mes' => 11]))->assertOk()->assertSee($dev)->assertSee($liq)->assertSee($irpf);
        // Editor y resumen del Integral
        $this->en()->get(route('laboral.integral', ['anio' => 2026, 'mes' => 11, 't' => $t->id]))->assertSee($liq);
        // Exportación del Integral y del cálculo de nóminas
        $csv = $this->en()->get(route('laboral.integral', ['anio' => 2026, 'mes' => 11, 'exportar' => 'csv']))->streamedContent();
        foreach ([$dev, $base, $irpf, $liq] as $x) {
            $this->assertStringContainsString($x, $csv);
        }
        $csv2 = $this->en()->get(route('laboral.calculo', ['anio' => 2026, 'mes' => 11, 'exportar' => 'csv']))->streamedContent();
        $this->assertStringContainsString($liq, $csv2);
        // Seguros sociales (RNT)
        $rnt = $this->en()->get(route('laboral.seguros', ['anio' => 2026, 'mes' => 11, 'exportar' => 'csv']))->streamedContent();
        $this->assertStringContainsString($base, $rnt);
        // Remesa SEPA: el líquido con punto decimal
        $xml = $this->en()->post(route('laboral.remesa.generar'), ['anio' => 2026, 'mes' => 11, 'cuenta' => 0, 'fecha' => '2026-11-30', 't' => [$t->id]])->getContent();
        $this->assertStringContainsString('>'.number_format((float) $calc['liq'], 2, '.', '').'<', $xml);
        // Asiento: el líquido pendiente de pago y la retención
        $this->en()->post(route('laboral.contabilizar'), ['anio' => 2026, 'mes' => 11])->assertSessionHasNoErrors();
        $ap = Apunte::where('asiento_id', Asiento::firstOrFail()->id)->get()->keyBy('cuenta');
        $this->assertSame(round((float) $calc['liq'], 2), round((float) $ap['465000000']->haber, 2));
        $this->assertSame(round((float) $calc['irpf'], 2), round((float) $ap['475100000']->haber, 2));
        // Modelo 111 del trimestre: la retención emitida
        $this->en()->get(route('laboral.m111', ['anio' => 2026, 't' => 4]))->assertSee(Formato::eur((float) $calc['irpf']));
    }
}
