<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Expediente;
use App\Models\Factura;
use App\Models\FacturacionConfig;
use App\Models\User;
use App\Support\Parametros;
use App\Support\Plazos;
use Carbon\CarbonImmutable;
use DOMDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ParametrosYFacturaeTest extends TestCase
{
    use RefreshDatabase;

    private Cliente $a;

    private User $admin;

    private User $as;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-11 10:00:00');
        CarbonImmutable::setTestNow('2026-11-11 10:00:00');
        $this->a = $this->cliente('Empresa A SL', 'B21890124', ['domicilio' => 'C/ Mayor 1', 'cp' => '21001', 'municipio' => 'Huelva']);
        $this->admin = $this->usuario(User::ADMIN);
        $this->as = $this->usuario(User::ASESOR);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function modo(string $m): void
    {
        $cfg = FacturacionConfig::de($this->a);
        $cfg->modo = $m;
        $cfg->save();
    }

    private function emitir(array $extra = [])
    {
        return $this->actingAs($this->as)->withSession(['cliente_activo' => $this->a->id])->post(route('facturacion.store'), $extra + [
            'serie' => 'A', 'fecha' => '2026-11-11', 'receptor_nombre' => 'Ayuntamiento de Huelva', 'receptor_nif' => 'P2104100F', 'receptor_dir' => 'Pl. Constitución 1',
            'fe_cp' => '21003', 'fe_municipio' => 'Huelva', 'fe_provincia' => '', 'dir3_oc' => 'L01210410', 'dir3_og' => 'L01210410', 'dir3_ut' => 'L01210410',
            'ret_pct' => '15', 'forma_pago' => 'Transferencia', 'pago_dias' => '30', 'iban_emisor' => 'ES9121000418450200051332',
            'lineas' => [['d' => 'Servicio', 'q' => '2', 'p' => '100,50', 'iva' => '21'], ['d' => 'Suplido · tasa', 'q' => '1', 'p' => '10', 'iva' => '21', 'sup' => '1']],
        ]);
    }

    public function test_factura_electronica_valida_contra_el_esquema_oficial_facturae(): void
    {
        $this->modo('electronica');
        $this->emitir()->assertRedirect();
        $f = Factura::first();
        $this->assertSame('electronica', $f->modo);
        $this->assertSame('Huelva', $f->datos_fe['provincia']);
        $r = $this->actingAs($this->as)->withSession(['cliente_activo' => $this->a->id])->get(route('facturacion.facturae', $f));
        $r->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $d = new DOMDocument;
        $d->loadXML($r->getContent());
        libxml_use_internal_errors(true);
        $ok = $d->schemaValidate(base_path('tests/fixtures/facturae/Facturaev3_2_2.xsd'));
        $this->assertTrue($ok, implode("\n", array_map(fn ($e) => $e->message, libxml_get_errors())));
        $this->assertStringContainsString('<CentreCode>L01210410</CentreCode>', $r->getContent());
        $this->actingAs($this->as)->withSession(['cliente_activo' => $this->a->id])->get(route('facturacion.show', $f))->assertSee('Factura electrónica (XML Facturae)');
    }

    public function test_electronica_exige_direccion_del_cliente_y_dir3_completo(): void
    {
        $this->modo('electronica');
        $this->emitir(['fe_cp' => ''])->assertSessionHasErrors('form');
        $this->emitir(['dir3_ut' => ''])->assertSessionHasErrors('form');
        $this->modo('normal');
        $this->emitir(['fe_cp' => ''])->assertRedirect();
        $this->assertNull(Factura::first()->datos_fe);
    }

    public function test_verifactu_elegible_pero_sin_envio_mientras_no_se_active(): void
    {
        $this->actingAs($this->admin)->withSession(['cliente_activo' => $this->a->id])->post(route('facturacion.config.guardar'), ['modo' => 'verifactu',
            'series' => [['cod' => 'A', 'nombre' => 'Ordinarias', 'tf' => 'F1'], ['cod' => 'R', 'nombre' => 'Rectificativas', 'tf' => 'R1']],
            'est' => ['modelo' => 'clasica', 'color' => '#0B0B0B', 'forma' => 'suave', 'cabecera' => 'relleno', 'margen' => 'normal', 'fuente' => 'sans']])->assertRedirect();
        $this->assertSame('verifactu', FacturacionConfig::where('cliente_id', $this->a->id)->value('modo'));
        $this->actingAs($this->as)->withSession(['cliente_activo' => $this->a->id])->get(route('facturacion.index'))->assertSee('envío a la AEAT pendiente de activar');
        $this->emitir()->assertRedirect();
        $this->assertSame('normal', Factura::first()->modo);
        $this->assertSame('Conservado', Factura::first()->registros->first()->estado);
    }

    public function test_parametros_solo_los_cambia_la_administracion_y_se_aplican(): void
    {
        foreach (array_keys(Parametros::MODULOS) as $m) {
            $this->actingAs($this->as)->get(route('parametros', $m))->assertOk()->assertSee('Solo la administración');
        }
        $this->actingAs($this->as)->post(route('parametros.guardar', 'documentos'), ['cupo_mb' => 500, 'aviso_caducidad' => 30])->assertForbidden();

        // Festivo local: el plazo se alarga
        $this->assertSame('2026-11-18', Plazos::vencimiento('2026-11-04', 10, 'h')->toDateString());
        $tipos = collect(Parametros::get('notificaciones')['tipos'])->map(fn ($t) => ['org' => $t[0], 'canal' => $t[1], 'tipo' => $t[2], 'n' => $t[3], 't' => $t[4], 'accion' => $t[5]])->all();
        $this->actingAs($this->admin)->post(route('parametros.guardar', 'notificaciones'), ['dias' => '5', 'comunidad' => 'AN', 'email' => '1', 'festivos' => '12/11/2026 Festivo local de prueba', 'tipos' => $tipos])->assertRedirect();
        Parametros::limpiar();
        Plazos::limpiarCache();
        $this->assertSame('2026-11-19', Plazos::vencimiento('2026-11-04', 10, 'h')->toDateString());
        $this->assertSame(5, Parametros::get('notificaciones')['dias']);
        $this->actingAs($this->admin)->post(route('parametros.guardar', 'notificaciones'), ['dias' => '3', 'festivos' => '31/02/2026 Mal', 'tipos' => $tipos])->assertSessionHasErrors('festivos');

        // Plantilla de expediente nueva
        $this->actingAs($this->admin)->post(route('parametros.guardar', 'expedientes'), ['portal_defecto' => '0', 'pl' => [['nombre' => 'Herencia', 'cat' => 'Sucesiones', 'hon' => '450,00', 'pasos' => "Inventario\nModelo 650", 'docs' => 'Certificado de defunción']]])->assertRedirect();
        Parametros::limpiar();
        $this->actingAs($this->as)->post(route('expedientes.store'), ['cliente_id' => $this->a->id, 'tramite' => 'Herencia'])->assertRedirect();
        $e = Expediente::first();
        $this->assertSame('450.00', $e->honorarios);
        $this->assertFalse($e->portal);
        $this->assertCount(2, $e->pasos);

        // CRM y documentos
        $this->actingAs($this->admin)->post(route('parametros.guardar', 'crm'), ['origenes' => "Web\nColegio profesional", 'validez' => '60', 'prob' => ['Nuevo' => 5, 'Contactado' => 20, 'Presupuesto enviado' => 50, 'Negociación' => 80, 'Ganado' => 100, 'Perdido' => 0], 'pie_presupuesto' => 'Gracias'])->assertRedirect();
        $this->actingAs($this->admin)->post(route('parametros.guardar', 'documentos'), ['cupo_mb' => 4096, 'aviso_caducidad' => 30])->assertRedirect();
        $this->actingAs($this->admin)->post(route('parametros.guardar', 'clientes'), ['periodicidad' => 'Trimestral', 'iva' => '21', 'forma_cobro' => 'Transferencia', 'concepto' => 'Asesoría'])->assertRedirect();
        Parametros::limpiar();
        $this->assertSame(['Web', 'Colegio profesional'], Parametros::get('crm')['origenes']);
        $this->actingAs($this->as)->withSession(['cliente_activo' => $this->a->id])->get(route('documentos.index'))->assertSee('Caducan en 30 días')->assertSee('de 4 GB');
        $this->actingAs($this->as)->withSession(['cliente_activo' => $this->a->id])->get(route('crm.index', ['nueva' => 1]))->assertSee('Colegio profesional');
    }
}
