<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\User;
use App\Services\VerificacionNif;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class VerificacionNifTest extends TestCase
{
    use RefreshDatabase;

    /** Certificado de prueba autofirmado en formato .p12 */
    private function p12(string $pass): UploadedFile
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['CN' => 'GA AGOPYME SL', 'serialNumber' => 'IDCES-B21890124', 'O' => 'Pruebas'], $key);
        $crt = openssl_csr_sign($csr, null, $key, 365);
        $out = '';
        openssl_pkcs12_export($crt, $out, $key, $pass);
        $tmp = tempnam(sys_get_temp_dir(), 'p12');
        file_put_contents($tmp, $out);

        return new UploadedFile($tmp, 'despacho.p12', 'application/x-pkcs12', null, true);
    }

    private function respuesta(string $resultado, string $nombre): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><env:Envelope xmlns:env="http://schemas.xmlsoap.org/soap/envelope/"><env:Body>'
            .'<VNifV2Sal:VNifV2Sal xmlns:VNifV2Sal="http://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/burt/jdit/ws/VNifV2Sal.xsd">'
            .'<VNifV2Sal:Contribuyente><VNifV2Sal:Nif>X</VNifV2Sal:Nif><VNifV2Sal:Nombre>'.$nombre.'</VNifV2Sal:Nombre><VNifV2Sal:Resultado>'.$resultado.'</VNifV2Sal:Resultado></VNifV2Sal:Contribuyente>'
            .'</VNifV2Sal:VNifV2Sal></env:Body></env:Envelope>';
    }

    public function test_certificado_y_verificacion_al_guardar_cliente(): void
    {
        Notification::fake();
        $admin = $this->usuario(User::ADMIN);
        $as = $this->usuario(User::ASESOR);

        // Contraseña incorrecta → error; correcta → guardado cifrado
        $this->actingAs($admin)->post(route('admin.aeat.guardar'), ['certificado' => $this->p12('clave-buena'), 'pass' => 'mala'])->assertSessionHasErrors('aeat');
        $this->actingAs($admin)->post(route('admin.aeat.guardar'), ['certificado' => $this->p12('clave-buena'), 'pass' => 'clave-buena'])->assertSessionHas('ok');
        $cert = app(VerificacionNif::class)->certificado();
        $this->assertSame('GA AGOPYME SL', $cert['titular']);
        $this->assertSame('B21890124', $cert['nif']);
        $this->assertTrue(app(VerificacionNif::class)->activo());
        $this->assertStringNotContainsString('clave-buena', (string) \DB::table('ajustes')->where('clave', 'aeat.certificado')->value('valor'));
        $this->actingAs($as)->post(route('admin.aeat.guardar'), ['certificado' => $this->p12('x'), 'pass' => 'x'])->assertForbidden();
        $this->actingAs($admin)->get(route('parametros', 'clientes'))->assertOk()->assertSee('Verificación del NIF en la AEAT')->assertSee('GA AGOPYME SL');

        $resp = null;
        $enviadas = 0;
        Http::fake(function () use (&$resp, &$enviadas) {
            $enviadas++;

            return $resp;
        });
        $base = ['tipo' => 'Sociedad', 'nombre' => 'Bar El Puerto SL', 'nif' => 'B21345673', 'asesor_id' => $as->id, 'provincia' => 'Huelva'];

        // NO IDENTIFICADO → no se guarda
        $resp = Http::response($this->respuesta('NO IDENTIFICADO', ''), 200);
        $this->actingAs($as)->post(route('clientes.store'), $base)->assertSessionHasErrors('form');
        $this->assertSame(0, Cliente::count());
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->body(), '<vnif:Nif>B21345673</vnif:Nif>') && str_contains($r->body(), '<vnif:Nombre>BAR EL PUERTO SL</vnif:Nombre>'));

        // BAJA → no se guarda
        $resp = Http::response($this->respuesta('IDENTIFICADO-BAJA', 'BAR EL PUERTO SL'), 200);
        $this->actingAs($as)->post(route('clientes.store'), $base)->assertSessionHasErrors('form');

        // IDENTIFICADO → se guarda con el nombre que consta en la AEAT
        $resp = Http::response($this->respuesta('IDENTIFICADO', 'BAR EL PUERTO SL'), 200);
        $this->actingAs($as)->post(route('clientes.store'), $base)->assertSessionHas('ok', fn ($m) => str_contains($m, 'NIF verificado en la AEAT'));
        $c = Cliente::first();
        $this->assertSame('IDENTIFICADO', $c->nif_aeat);
        $this->assertSame('BAR EL PUERTO SL', $c->nif_aeat_nombre);

        // Sin conexión → se guarda como pendiente, con aviso
        $resp = Http::response('', 503);
        $this->actingAs($as)->post(route('clientes.store'), ['nombre' => 'Otra SL', 'nif' => 'B21987656'] + $base)->assertSessionHas('ok', fn ($m) => str_contains($m, 'sin verificar'));
        $this->assertSame('PENDIENTE', Cliente::where('nif', 'B21987656')->value('nif_aeat'));

        // Desactivada: no se consulta
        $this->actingAs($admin)->post(route('admin.aeat.activar'), ['activo' => 0])->assertSessionHas('ok');
        $antes = $enviadas;
        $resp = Http::response($this->respuesta('NO IDENTIFICADO', ''), 200);
        $this->actingAs($as)->post(route('clientes.store'), ['nombre' => 'Tercera SL', 'nif' => 'A58818501'] + $base)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($antes, $enviadas);
    }

    public function test_interpretar_fault_y_respuestas(): void
    {
        $s = app(VerificacionNif::class);
        $this->assertSame('error', $s->interpretar(500, '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body><soapenv:Fault><faultcode>x</faultcode><faultstring>Certificado no válido</faultstring></soapenv:Fault></soapenv:Body></soapenv:Envelope>')['estado']);
        $this->assertSame('revocado', $s->interpretar(200, $this->respuesta('IDENTIFICADO-REVOCADO', 'X'))['estado']);
        $this->assertSame('ok', $s->interpretar(200, $this->respuesta('IDENTIFICADO', 'X'))['estado']);
        $this->assertStringContainsString('ESPA&#xD1;OL', str_replace('Ñ', '&#xD1;', $s->peticion('99999999R', 'Español Español Juan')));
    }
}
