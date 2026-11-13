<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Documento;
use App\Models\Notificacion;
use App\Models\User;
use App\Services\AlmacenDocumentos;
use App\Services\Dehu\Lema;
use App\Services\Dehu\NotificacionesDehu;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** DEHú (LEMA) con un servidor simulado: localiza no abre nada; comparecer solo a petición y guarda los documentos */
class DehuTest extends TestCase
{
    use RefreshDatabase;

    private Cliente $desp;

    private Cliente $a;

    private User $as;

    /** Llamadas recibidas por el servidor simulado: [SOAPAction, xml] */
    private array $llamadas = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-12 10:00:00');
        CarbonImmutable::setTestNow('2026-11-12 10:00:00');
        $this->desp = $this->cliente('GA AGOPYME SL', 'B21000000');
        $this->desp->es_despacho = true;
        $this->desp->save();
        $this->a = $this->cliente('Empresa A SL', 'B21890124');
        $this->as = $this->usuario(User::ASESOR);

        // Certificado de prueba en la ficha del cliente (.p12 cifrado en Documentos)
        $k = openssl_pkey_new(['private_key_bits' => 2048]);
        $x = openssl_csr_sign(openssl_csr_new(['CN' => 'EMPRESA A SL', 'serialNumber' => 'IDCES-99999999R', 'organizationIdentifier' => 'VATES-B21890124'], $k), null, $k, 365);
        openssl_pkcs12_export($x, $p12, $k, 'clave');
        $doc = $this->documento($this->a, ['carpeta' => 'ident/certdig', 'tipo' => 'cert'], $p12);
        $this->a->setSeccion('certs', [['tipo' => 'Representante', 'titular' => 'EMPRESA A SL', 'fCad' => '2027-11-12', 'archivo' => ['id' => $doc->id, 'name' => 'cert.p12'], 'pass' => 'clave', 'aeat' => true, 'dehu' => true, 'gd' => true]]);
        $this->a->save();

        $this->app->instance(NotificacionesDehu::class, new NotificacionesDehu(app(AlmacenDocumentos::class), new Lema('se', function ($url, $action, $xml) {
            $this->llamadas[] = [basename($action), $xml];

            return $this->respuesta(basename($action));
        })));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function respuesta(string $accion): array
    {
        $env = fn ($b) => '<?xml version="1.0"?><S:Envelope xmlns:S="http://schemas.xmlsoap.org/soap/envelope/"><S:Body>'.$b.'</S:Body></S:Envelope>';
        $org = '<ns1:codigoOrganismo>E00004101</ns1:codigoOrganismo><ns1:nombreOrganismo>Agencia Estatal de Administración Tributaria</ns1:nombreOrganismo>';
        $item = fn ($id, $f, $con) => '<ns1:item><ns1:identificador>'.$id.'</ns1:identificador><ns1:codigoOrigen>2</ns1:codigoOrigen><ns1:concepto>'.$con.'</ns1:concepto>'
            .'<ns1:organismoEmisor>'.$org.'</ns1:organismoEmisor><ns1:organismoEmisorRaiz>'.$org.'</ns1:organismoEmisorRaiz>'
            .'<ns1:fechaPuestaDisposicion>'.$f.'</ns1:fechaPuestaDisposicion><ns1:tipoEnvio>2</ns1:tipoEnvio><ns1:vinculo>1</ns1:vinculo></ns1:item>';

        return match ($accion) {
            'Localiza' => [200, 'text/xml', $env('<ns1:RespuestaLocaliza xmlns:ns1="urn:x"><ns1:codigoRespuesta>200</ns1:codigoRespuesta><ns1:envios>'
                .$item('ENV-1', '2026-11-10T09:00:00+01:00', 'Requerimiento IVA 3T').$item('ENV-2', '2026-10-20T09:00:00+02:00', 'Liquidación provisional')
                .'</ns1:envios><ns1:hayMasResultados>false</ns1:hayMasResultados></ns1:RespuestaLocaliza>')],
            'PeticionAcceso' => [200, 'multipart/related; type="text/xml"; boundary="B1"', "--B1\r\nContent-Type: text/xml\r\nContent-ID: <root>\r\n\r\n"
                .$env('<ns1:RespuestaPeticionAcceso xmlns:ns1="urn:x"><ns1:codigoRespuesta>200</ns1:codigoRespuesta><ns1:fechaEvento>2026-11-12T10:00:00+01:00</ns1:fechaEvento>'
                .'<ns1:documento><ns1:nombre>requerimiento.pdf</ns1:nombre><ns1:mimeType>application/pdf</ns1:mimeType><ns1:contenido>cid:doc1</ns1:contenido></ns1:documento>'
                .'<ns1:anexos><ns1:anexoReferencia><ns1:referenciaDocumento>AX1</ns1:referenciaDocumento><ns1:nombre>anexo.pdf</ns1:nombre></ns1:anexoReferencia></ns1:anexos>'
                .'<ns1:referenciaPdfAcuse>AC1</ns1:referenciaPdfAcuse><ns1:csvResguardo>CSV123</ns1:csvResguardo></ns1:RespuestaPeticionAcceso>')
                ."\r\n--B1\r\nContent-Type: application/pdf\r\nContent-ID: <doc1>\r\n\r\n%PDF-1.4 requerimiento\r\n--B1--\r\n"],
            'ConsultaAnexos' => [200, 'text/xml', $env('<ns1:RespuestaConsultaAnexos xmlns:ns1="urn:x"><ns1:codigoRespuesta>200</ns1:codigoRespuesta><ns1:documento><ns1:nombre>anexo.pdf</ns1:nombre><ns1:mimeType>application/pdf</ns1:mimeType><ns1:contenido>'.base64_encode('%PDF-1.4 anexo').'</ns1:contenido></ns1:documento></ns1:RespuestaConsultaAnexos>')],
            'ConsultaAcusePdf' => [200, 'text/xml', $env('<ns1:RespuestaConsultaAcusePdf xmlns:ns1="urn:x"><ns1:codigoRespuesta>200</ns1:codigoRespuesta><ns1:acusePdf><ns1:nombreAcuse>acuse.pdf</ns1:nombreAcuse><ns1:contenido>'.base64_encode('%PDF-1.4 acuse').'</ns1:contenido></ns1:acusePdf></ns1:RespuestaConsultaAcusePdf>')],
        };
    }

    public function test_consultar_lista_sin_abrir_y_no_duplica(): void
    {
        $this->actingAs($this->as)->withSession(['cliente_activo' => $this->a->id])->post(route('notificaciones.dehu'))->assertSessionHas('ok');
        $this->assertSame(['Localiza'], array_column($this->llamadas, 0));
        $n = Notificacion::where('dehu_id', 'ENV-1')->firstOrFail();
        $this->assertSame(['AEAT', 'DEHú', 'Pendiente', '2026-11-10'], [$n->organismo, $n->canal, $n->estado, $n->puesta->toDateString()]);
        $this->assertStringContainsString('Requerimiento IVA 3T', $n->tipo);
        // La de hace más de 10 días se da por notificada por caducidad (art. 43.2 Ley 39/2015)
        $v = Notificacion::where('dehu_id', 'ENV-2')->firstOrFail();
        $this->assertTrue($v->caducada);
        $this->assertSame('Rechazada', $v->estado);

        $this->actingAs($this->as)->withSession(['cliente_activo' => $this->a->id])->post(route('notificaciones.dehu'));
        $this->assertSame(2, Notificacion::count());
        $this->assertTrue($this->a->fresh()->datos['dehuSync']['ok']);
        $this->actingAs($this->as)->withSession(['cliente_activo' => $this->a->id])->get(route('notificaciones.index'))->assertOk()
            ->assertSee('Consultar ahora')->assertSee('Conectada con el certificado de EMPRESA A SL')->assertSee('Requerimiento IVA 3T');
    }

    public function test_comparecer_pide_confirmacion_abre_y_guarda_documentos(): void
    {
        app(NotificacionesDehu::class)->sincronizar($this->a);
        $n = Notificacion::where('dehu_id', 'ENV-1')->firstOrFail();
        $this->actingAs($this->as)->withSession(['cliente_activo' => $this->a->id])->get(route('notificaciones.index', ['sel' => $n->id, 'ask' => 'dehu']))
            ->assertOk()->assertSee('Abrir en la DEHú')->assertSee('art. 43.2 Ley 39/2015')->assertDontSee('Abrir y aceptar');

        $this->actingAs($this->as)->post(route('notificaciones.comparecer', $n))->assertSessionHasErrors('confirmo');
        $this->assertSame('Pendiente', $n->fresh()->estado);
        $this->assertNotContains('PeticionAcceso', array_column($this->llamadas, 0));

        $this->actingAs($this->as)->post(route('notificaciones.comparecer', $n), ['confirmo' => 1])->assertRedirect()->assertSessionHas('ok');
        $n->refresh();
        $this->assertSame(['Aceptada', '2026-11-12'], [$n->estado, $n->acceso->toDateString()]);
        $this->assertSame(['Localiza', 'PeticionAcceso', 'ConsultaAnexos', 'ConsultaAcusePdf'], array_column($this->llamadas, 0));
        $this->assertStringContainsString('<pac:nifReceptor>B21890124</pac:nifReceptor>', $this->llamadas[1][1]);
        $ids = json_decode($n->dehu, true)['documentos'];
        $this->assertCount(3, $ids);
        $docs = Documento::withoutGlobalScopes()->whereIn('id', $ids)->get();
        $this->assertTrue($docs->every(fn ($d) => $d->carpeta === 'fiscal/requerimientos' && $d->compartido && $d->cliente_id === $this->a->id));
        $this->assertSame('%PDF-1.4 requerimiento', app(AlmacenDocumentos::class)->contenido($docs->firstWhere('id', $ids[0])->ruta));

        // El portal del cliente la ve (solo lectura) con sus documentos
        $uA = $this->usuario(User::CLIENTE_ADMIN, $this->a);
        $this->actingAs($uA)->get(route('portal.notificaciones'))->assertOk()->assertSee('Requerimiento IVA 3T')->assertSee('requerimiento.pdf')->assertDontSee('Abrir en la DEHú');
        $this->actingAs($uA)->post(route('notificaciones.dehu'))->assertForbidden();
    }

    public function test_sin_certificado_listo_explica_el_motivo(): void
    {
        $l = $this->a->seccion('certs');
        $l[0]['gd'] = false;
        $this->a->setSeccion('certs', $l);
        $this->a->save();
        $this->assertSame('Falta el alta como Gran Destinatario en la DEHú', NotificacionesDehu::certificado($this->a->fresh())[1]);
        $this->actingAs($this->as)->withSession(['cliente_activo' => $this->desp->id])->post(route('notificaciones.dehu'))->assertSessionHasErrors('dehu');
        $this->assertSame([], $this->llamadas);
        $this->artisan('agcloud:dehu')->assertSuccessful();
        $this->assertSame([], $this->llamadas);
    }
}
