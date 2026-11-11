<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Factura;
use App\Models\FacturacionConfig;
use App\Models\Oportunidad;
use App\Models\Presupuesto;
use App\Models\RegistroFacturacion;
use App\Models\User;
use App\Services\Facturacion;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class FacturacionCrmTest extends TestCase
{
    use RefreshDatabase;

    private Cliente $a;

    private Cliente $b;

    private User $as;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-11 10:00:00');
        CarbonImmutable::setTestNow('2026-11-11 10:00:00');
        $this->a = $this->cliente('Empresa A SL', 'B21890124', ['domicilio' => 'C/ Mayor 1', 'cp' => '21001', 'municipio' => 'Huelva']);
        $this->b = $this->cliente('Empresa B SL', 'B21112230');
        $this->as = $this->usuario(User::ASESOR);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function en(Cliente $c)
    {
        return $this->actingAs($this->as)->withSession(['cliente_activo' => $c->id]);
    }

    private function emitir(array $extra = [], ?Cliente $c = null)
    {
        return $this->en($c ?? $this->a)->post(route('facturacion.store'), $extra + [
            'serie' => 'A', 'fecha' => '2026-11-11', 'receptor_nombre' => 'Cliente Final SL', 'receptor_nif' => 'B21000005', 'receptor_dir' => 'C/ Uno 1',
            'ret_pct' => '0', 'forma_pago' => 'Transferencia', 'pago_dias' => '30', 'iban' => '', 'iban_emisor' => '',
            'lineas' => [['d' => 'Servicio', 'q' => '2', 'p' => '100,50', 'iva' => '21'], ['d' => 'Suplido · tasa', 'q' => '1', 'p' => '10', 'iva' => '21', 'sup' => '1']],
        ]);
    }

    public function test_huella_coincide_con_el_ejemplo_oficial_de_la_aeat(): void
    {
        $this->assertSame('3C464DAF61ACB827C65FDA19F352A4E3BDC2C640E9E9FC4CC058073F38F12F60',
            Facturacion::huellaAlta('89890001K', '12345678/G33', '2024-01-01', 'F1', 12.35, 123.45, null, '2024-01-01T19:20:30+01:00'));
    }

    public function test_totales_con_suplidos_retencion_y_redondeo(): void
    {
        $t = Facturacion::totales([['d' => 'x', 'q' => 3, 'p' => 33.33, 'iva' => 21], ['d' => 'y', 'q' => 1, 'p' => 10, 'iva' => 10], ['d' => 's', 'q' => 1, 'p' => 5, 'iva' => 0, 'sup' => true]], 15);
        $this->assertSame(109.99, $t['base']);
        $this->assertSame(22.0, $t['cuota']); // 99,99 × 21 % = 21,00 · 10 × 10 % = 1,00
        $this->assertSame(16.5, $t['retencion']);
        $this->assertSame(5.0, $t['suplidos']);
        $this->assertSame(round(109.99 + 22 - 16.5 + 5, 2), $t['total']);
    }

    public function test_emitir_numerar_encadenar_y_verificar(): void
    {
        $this->emitir()->assertRedirect();
        $this->emitir()->assertRedirect();
        $f1 = Factura::orderBy('id')->first();
        $f2 = Factura::orderBy('id')->skip(1)->first();
        $this->assertSame('A-2026-0001', $f1->num);
        $this->assertSame('A-2026-0002', $f2->num);
        $this->assertSame('201.00', $f1->base);
        $this->assertSame('42.21', $f1->cuota);
        $this->assertSame('10.00', $f1->suplidos);
        $this->assertSame('253.21', $f1->total);
        $r = RegistroFacturacion::orderBy('id')->get();
        $this->assertCount(2, $r);
        $this->assertNull($r[0]->huella_anterior);
        $this->assertSame($r[0]->huella, $r[1]->huella_anterior);
        $this->assertNull(app(Facturacion::class)->verificar($this->a));

        // La factura del otro emisor empieza su propia cadena y numeración
        $this->emitir([], $this->b);
        $this->assertSame('A-2026-0001', Factura::where('cliente_id', $this->b->id)->value('num'));

        // Si alguien altera la base de datos, la verificación lo detecta
        DB::table('registros_facturacion')->where('id', $r[0]->id)->update(['total' => 999]);
        $this->assertStringContainsString('A-2026-0001', (string) app(Facturacion::class)->verificar($this->a));
    }

    public function test_factura_inalterable_y_anulacion_encadenada(): void
    {
        $this->emitir();
        $f = Factura::first();
        $this->expectException(LogicException::class);
        try {
            $f->total = 1;
            $f->save();
        } finally {
            $f->refresh();
            $this->en($this->a)->post(route('facturacion.anular', $f))->assertRedirect();
            $this->assertSame('Anulada', $f->fresh()->estado);
            $anul = RegistroFacturacion::orderByDesc('id')->first();
            $this->assertSame('Anulación', $anul->tipo);
            $this->assertNull(app(Facturacion::class)->verificar($this->a));
            $this->assertSame(2, RegistroFacturacion::count());
            $this->assertSame(1, Factura::count());
        }
    }

    public function test_validaciones_de_emision(): void
    {
        $this->emitir(['fecha' => '2026-11-12'])->assertSessionHasErrors('fecha');
        $this->emitir(['receptor_nif' => ''])->assertSessionHasErrors('form');
        $this->emitir(['forma_pago' => 'Domiciliación SEPA', 'iban' => 'ES00123'])->assertSessionHasErrors('form');
        $this->emitir(['serie' => 'R'])->assertSessionHasErrors('form'); // rectificativa sin original
        $this->emitir()->assertRedirect();
        $this->emitir(['fecha' => '2026-11-10'])->assertSessionHasErrors('form'); // anterior a la última de la serie
        $this->assertSame(1, Factura::count());
    }

    public function test_rectificativa_desde_la_original(): void
    {
        $this->emitir();
        $f = Factura::first();
        $this->en($this->a)->get(route('facturacion.create', ['rectifica' => $f->id]))->assertOk()->assertSee('Rectificativa de A-2026-0001');
        $this->en($this->a)->post(route('facturacion.store'), [
            'serie' => 'R', 'fecha' => '2026-11-11', 'receptor_nombre' => 'Cliente Final SL', 'receptor_nif' => 'B21000005', 'ret_pct' => '0', 'forma_pago' => 'Transferencia', 'pago_dias' => '30',
            'rectifica_id' => $f->id, 'lineas' => [['d' => 'Rectificación · Servicio', 'q' => '−1,00', 'p' => '100,50', 'iva' => '21']],
        ])->assertRedirect();
        $r = Factura::where('tipo', 'R1')->first();
        $this->assertSame('R-2026-0001', $r->num);
        $this->assertSame('-121.61', $r->total);
        $this->assertSame($f->id, $r->rectifica_id);
        $this->en($this->a)->get(route('facturacion.documento', $r))->assertOk()->assertSee('Rectifica la factura A-2026-0001')->assertSee('<svg', false);
    }

    public function test_aislamiento_entre_empresas_y_portal(): void
    {
        $this->emitir();
        $f = Factura::first();
        $this->en($this->b)->get(route('facturacion.show', $f))->assertNotFound();
        $this->en($this->b)->post(route('facturacion.anular', $f))->assertNotFound();
        $uA = $this->usuario(User::CLIENTE_ADMIN, $this->a);
        $this->actingAs($uA)->get(route('facturacion.index'))->assertForbidden();
        $this->actingAs($uA)->get(route('crm.index'))->assertForbidden();
        $this->actingAs($uA);
        $this->assertSame(0, Factura::count());
        $this->assertSame(0, RegistroFacturacion::count());
    }

    public function test_crm_presupuesto_aceptado_y_convertido_en_factura(): void
    {
        $this->en($this->a)->post(route('crm.op.store'), ['nombre' => 'Hotel Costa SL', 'titulo' => 'Asesoría fiscal', 'importe' => '1.200', 'etapa' => 'Nuevo', 'fecha' => '2026-12-01', 'origen' => 'Web'])->assertRedirect();
        $o = Oportunidad::first();
        $this->assertSame('1200.00', $o->importe);
        $this->en($this->a)->post(route('crm.pres.store'), ['cli' => 'Hotel Costa SL', 'nif' => 'B21000005', 'validez' => '30', 'oportunidad_id' => $o->id, 'lineas' => [['d' => 'Asesoría fiscal anual', 'q' => '1', 'p' => '1.200,00', 'iva' => '21']]])->assertRedirect();
        $p = Presupuesto::first();
        $this->assertSame('P-2026-0001', $p->num);
        $this->assertSame('1452.00', $p->total);
        $this->en($this->a)->post(route('crm.pres.estado', $p), ['estado' => 'Enviado'])->assertSessionHas('mailto');
        $this->assertSame('Presupuesto enviado', $o->fresh()->etapa);
        $this->en($this->a)->post(route('crm.pres.estado', $p), ['estado' => 'Aceptado']);
        $this->assertSame('Ganado', $o->fresh()->etapa);
        $this->en($this->a)->get(route('facturacion.create', ['presupuesto' => $p->id]))->assertOk()->assertSee('Según presupuesto P-2026-0001')->assertSee('Asesoría fiscal anual');
        $this->emitir(['receptor_nombre' => 'Hotel Costa SL', 'presupuesto_id' => $p->id, 'lineas' => [['d' => 'Asesoría fiscal anual', 'q' => '1', 'p' => '1.200,00', 'iva' => '21']]]);
        $p->refresh();
        $this->assertSame('Facturado', $p->estado);
        $this->assertSame(Factura::first()->id, $p->factura_id);
        $this->en($this->a)->get(route('crm.index', ['tab' => 'pres']))->assertOk()->assertSee('Facturado');
        $this->en($this->a)->post(route('crm.act.store'), ['tipo' => 'Llamada', 'texto' => 'Llamar para firmar', 'fecha' => '2026-11-12'])->assertRedirect();
        $this->en($this->a)->get(route('crm.index', ['tab' => 'agenda']))->assertOk()->assertSee('Llamar para firmar')->assertSee('Agenda · 1');
    }

    public function test_pantallas_cargan(): void
    {
        $this->emitir();
        foreach (['facturacion.index', 'facturacion.create', 'facturacion.registro', 'facturacion.config', 'facturacion.muestra', 'crm.index'] as $r) {
            $this->en($this->a)->get(route($r))->assertOk();
        }
        $this->en($this->a)->get(route('facturacion.index', ['exportar' => 'csv']))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->en($this->a)->post(route('facturacion.verificar'))->assertSessionHas('chk', fn ($c) => $c['ok'] === true);
        $this->en($this->a)->post(route('facturacion.config.guardar'), ['pie' => 'Gracias', 'series' => [['cod' => 'A', 'nombre' => 'Ordinarias', 'tf' => 'R1'], ['cod' => 'S', 'nombre' => 'Simplificadas', 'tf' => 'F2'], ['cod' => 'R', 'nombre' => 'Rectificativas', 'tf' => 'R1']], 'nueva_serie' => 'b',
            'est' => ['modelo' => 'moderna', 'color' => '#1f3a5f', 'forma' => 'suave', 'cabecera' => 'relleno', 'margen' => 'normal', 'fuente' => 'sans', 'cuadros' => '1'], 'cat' => [['nombre' => 'Cuota mensual', 'precio' => '120,00', 'iva' => '21']]])->assertRedirect();
        $cfg = FacturacionConfig::where('cliente_id', $this->a->id)->first();
        $this->assertSame('F1', $cfg->serie('A')['tf']); // con facturas: no cambia de tipo
        $this->assertNotNull($cfg->serie('B'));
        $this->assertSame('#1F3A5F', $cfg->estilo['color']);
    }
}
