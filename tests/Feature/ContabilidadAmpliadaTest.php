<?php

namespace Tests\Feature;

use App\Models\Apunte;
use App\Models\Asiento;
use App\Models\BandejaDocumento;
use App\Models\Cliente;
use App\Models\Cuenta;
use App\Models\Documento;
use App\Models\Factura;
use App\Models\FacturaRecibida;
use App\Models\MovimientoBanco;
use App\Models\Tercero;
use App\Models\User;
use App\Services\Contabilidad;
use App\Services\Facturacion;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ContabilidadAmpliadaTest extends TestCase
{
    use RefreshDatabase;

    private Cliente $a;

    private User $as;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-13 10:00:00');
        CarbonImmutable::setTestNow('2026-11-13 10:00:00');
        $this->a = $this->cliente('Empresa A SL', 'B21890124');
        $this->as = $this->usuario(User::ASESOR);
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

    private function apuntes(Asiento $a): array
    {
        return $a->apuntes()->get()->map(fn ($p) => [$p->cuenta, (float) $p->debe, (float) $p->haber])->all();
    }

    public function test_menu_listados_y_pgc(): void
    {
        $this->en()->post(route('contabilidad.apuntes.guardar'), ['fecha' => '2026-02-10', 'concepto' => 'Capital', 'l' => [['c' => '572', 'd' => '3000'], ['c' => '100', 'h' => '3000']]]);
        $this->en()->post(route('contabilidad.apuntes.guardar'), ['fecha' => '2026-05-10', 'concepto' => 'Comisión', 'l' => [['c' => '626', 'd' => '10'], ['c' => '572', 'h' => '10']]]);
        $r = $this->en()->get(route('contabilidad.index'))->assertOk();
        foreach (['Listados contables', 'Extracto de cuenta', 'Cuenta de explotación (PyG)', 'Balance de sumas y saldos', 'Listado diario de movimientos', 'Listado mayor de cuentas', 'Balances oficiales', 'PGC', 'Conciliador bancario', 'Registro Mercantil', 'Emisión de libros contables (Legalia)', 'Depósito de cuentas (D2)'] as $t) {
            $r->assertSee($t);
        }
        $this->en()->get(route('contabilidad.pyg'))->assertOk()->assertSee('Otros gastos de explotación')->assertSee('−10,00');
        $this->en()->get(route('contabilidad.ldiario'))->assertOk()->assertSee('LISTADO DIARIO DE MOVIMIENTOS')->assertSee('Totales · 2 asientos');
        $this->en()->get(route('contabilidad.lmayor', ['de' => '572', 'a' => '572']))->assertOk()->assertSee('572000000')->assertSee('2.990,00')->assertDontSee('<span class="mono" style="margin-right:8px">626000000</span>', false);
        $this->en()->get('/contabilidad/mayor')->assertRedirect('/contabilidad/listados/extracto');
        // El número del menú solo sale en lo pendiente: el Libro diario no lleva contador
        $this->en()->get(route('contabilidad.index'))->assertSee('Libro diario</a>', false);

        // PGC: alta de subcuenta, baja de una cuenta del plan sin movimientos y reactivación
        $this->en()->get(route('contabilidad.pgc', ['q' => '572']))->assertOk()->assertSee('572000000');
        $this->en()->post(route('contabilidad.pgc.guardar'), ['codigo' => '572.7', 'nombre' => 'Banco Santander c/c 4821'])->assertSessionHas('ok');
        $this->assertTrue(Cuenta::where('codigo', '572000007')->exists());
        $this->en()->post(route('contabilidad.pgc.guardar'), ['codigo' => '999000001', 'nombre' => 'Mal'])->assertSessionHasErrors('pgc');
        $this->en()->delete(route('contabilidad.pgc.borrar', '572000000'))->assertSessionHas('aviso');   // tiene movimientos
        $this->en()->delete(route('contabilidad.pgc.borrar', '629000000'))->assertSessionHas('ok');
        $this->en()->post(route('contabilidad.apuntes.guardar'), ['fecha' => '2026-05-11', 'concepto' => 'X', 'l' => [['c' => '629', 'd' => '1'], ['c' => '572', 'h' => '1']]])->assertSessionHasErrors('form');
        $this->en()->get(route('contabilidad.pgc', ['ver' => 'bajas']))->assertSee('629000000');
        $this->en()->post(route('contabilidad.pgc.reactivar', '629000000'))->assertSessionHas('ok');
        $this->en()->delete(route('contabilidad.pgc.borrar', '572000007'))->assertSessionHas('ok');
        $this->assertFalse(Cuenta::where('codigo', '572000007')->exists());
    }

    public function test_modificar_y_eliminar_cuentas(): void
    {
        $this->en()->post(route('contabilidad.pgc.guardar'), ['codigo' => '572.7', 'nombre' => 'Banco Santander']);
        $this->en()->post(route('contabilidad.apuntes.guardar'), ['fecha' => '2026-02-10', 'concepto' => 'Capital', 'l' => [['c' => '572000007', 'd' => '3000'], ['c' => '100', 'h' => '3000']]])->assertSessionHasNoErrors();
        // Modificar nombre y código de una subcuenta con movimientos: sus apuntes pasan a la nueva
        $this->en()->get(route('contabilidad.pgc', ['q' => '572', 'editar' => '572000007']))->assertOk()->assertSee('Si cambias el código');
        $this->en()->post(route('contabilidad.pgc.editar', '572000007'), ['codigo' => '572000008', 'nombre' => 'Santander cuenta nómina'])->assertSessionHas('ok');
        $this->assertSame('Santander cuenta nómina', Cuenta::where('codigo', '572000008')->value('nombre'));
        $this->assertSame(1, Apunte::where('cuenta', '572000008')->count());
        $this->assertSame(0, Apunte::where('cuenta', '572000007')->count());
        // Código ocupado o fuera del PGC
        $this->en()->post(route('contabilidad.pgc.editar', '572000008'), ['codigo' => '100000000', 'nombre' => 'X'])->assertSessionHasErrors('pgcEd');
        // Cuenta del PGC: solo el nombre
        $this->en()->post(route('contabilidad.pgc.editar', '100000000'), ['codigo' => '100000001', 'nombre' => 'X'])->assertSessionHasErrors('pgcEd');
        $this->en()->post(route('contabilidad.pgc.editar', '100000000'), ['nombre' => 'Capital social suscrito'])->assertSessionHas('ok');
        $this->assertSame('Capital social suscrito', Contabilidad::nombre('100000000', Contabilidad::propias($this->a)));
        $this->en()->get(route('contabilidad.pgc', ['q' => '100']))->assertSee('Capital social suscrito');
        $this->en()->get(route('contabilidad.pgc', ['g' => '5']))->assertSee('572000008')->assertDontSee('100000000');
        // Con movimientos no se elimina: hay que cambiarla antes
        $this->en()->delete(route('contabilidad.pgc.borrar', '572000008'))->assertSessionHas('aviso');
        $this->assertTrue(Cuenta::where('codigo', '572000008')->exists());
        $this->en()->get(route('contabilidad.pgc', ['q' => '572']))->assertSee('Con movimientos');
        // Cuenta del PGC sin movimientos: baja y reactivación
        $this->en()->delete(route('contabilidad.pgc.borrar', '629000000'))->assertSessionHas('ok');
        $this->en()->post(route('contabilidad.pgc.reactivar', '629000000'))->assertSessionHas('ok');
        $this->assertFalse(Cuenta::where('codigo', '629000000')->exists());
        // Proveedor sin facturas: modificar y eliminar desde el PGC
        $t = Tercero::create(['cliente_id' => $this->a->id, 'clase' => 'proveedor', 'cuenta' => '400000001', 'nombre' => 'Papelería', 'forma_pago' => 'Transferencia', 'pago_dias' => 30]);
        $this->en()->post(route('contabilidad.pgc.editar', '400000001'), ['codigo' => '410000001', 'nombre' => 'X'])->assertSessionHasErrors('pgcEd');
        $this->en()->post(route('contabilidad.pgc.editar', '400000001'), ['codigo' => '400000005', 'nombre' => 'Papelería del Sur SL'])->assertSessionHas('ok');
        $this->assertSame('400000005', $t->fresh()->cuenta);
        $this->assertSame('Papelería del Sur SL', $t->fresh()->nombre);
        $this->en()->delete(route('contabilidad.pgc.borrar', '400000005'))->assertSessionHas('ok');
        $this->assertNull(Tercero::find($t->id));
        // Cambiar la cuenta desde la ficha del proveedor en Gestión también mueve sus apuntes
        $p = Tercero::create(['cliente_id' => $this->a->id, 'clase' => 'proveedor', 'cuenta' => '400000002', 'nombre' => 'Suministros', 'forma_pago' => 'Transferencia', 'pago_dias' => 30]);
        $this->en()->post(route('contabilidad.apuntes.guardar'), ['fecha' => '2026-04-10', 'concepto' => 'Compra', 'l' => [['c' => '600', 'd' => '50'], ['c' => '400000002', 'h' => '50']]])->assertSessionHasNoErrors();
        $this->en()->delete(route('contabilidad.pgc.borrar', '400000002'))->assertSessionHas('aviso');
        $this->en()->post(route('proveedores.update', $p), ['nombre' => 'Suministros', 'forma_pago' => 'Transferencia', 'pago_dias' => '30', 'cuenta_modo' => 'manual', 'cuenta' => '400000009'])->assertSessionHasNoErrors();
        $this->assertSame(1, Apunte::where('cuenta', '400000009')->count());
        $this->assertSame(0, Apunte::where('cuenta', '400000002')->count());
    }

    public function test_apunte_de_nomina_con_documento_y_vista_guardada(): void
    {
        $this->en()->get(route('contabilidad.apuntes'))->assertOk()->assertSee('Plantillas predefinidas')->assertSee('Pago de seguros sociales')->assertSee('Importar desde nóminas');
        $pdf = UploadedFile::fake()->createWithContent('nomina-octubre.pdf', '%PDF-1.4 nomina');
        $this->en()->post(route('contabilidad.apuntes.guardar'), ['fecha' => '2026-10-31', 'concepto' => 'Nómina de octubre de 2026', 'tipo' => 'Nómina', 'archivo' => $pdf, 'l' => [
            ['c' => '640000000', 'd' => '1850,00', 't' => 'Nómina de octubre de 2026'], ['c' => '642000000', 'd' => '594,78'],
            ['c' => '475100000', 'h' => '222,00'], ['c' => '476000000', 'h' => '715,03'], ['c' => '465000000', 'h' => '1507,75'],
        ]])->assertRedirect();
        $a = Asiento::first();
        $this->assertSame('Nómina', $a->tipo);
        $this->assertNotNull($a->documento_id);
        $this->assertSame('laboral/nominas', $a->fresh()->documento_id ? Documento::find($a->documento_id)->carpeta : null);
        $this->en()->get(route('contabilidad.apuntes', ['guardado' => $a->id]))->assertOk()->assertSee('ASIENTO 1 GUARDADO')->assertSee('1.507,75');
    }

    public function test_bandeja_subir_revisar_y_contabilizar(): void
    {
        $this->en()->post(route('contabilidad.bandeja.subir'), ['archivos' => [UploadedFile::fake()->createWithContent('ticket.pdf', '%PDF-1.4 x')]])->assertRedirect();
        $bd = BandejaDocumento::first();
        $this->en()->get(route('contabilidad.bandeja', ['revisar' => 'd'.$bd->id]))->assertOk()->assertSee('Leer con OCR')->assertSee('Contabilizar');
        // NIF mal leído → error; bien → crea el acreedor, la factura y el asiento
        $base = ['clase' => 'Recibida', 'nombre' => 'Papelería Sur SL', 'nif' => 'B21345670', 'tipo_ter' => 'acreedor', 'num' => 'T-55', 'fecha' => '2026-11-02', 'base' => '100,00', 'iva_pct' => '21', 'ret_pct' => '0', 'deducible' => '1', 'cuenta' => '629'];
        $this->en()->post(route('contabilidad.bandeja.revisar', 'd'.$bd->id), $base)->assertSessionHasErrors('rev');
        $this->en()->post(route('contabilidad.bandeja.revisar', 'd'.$bd->id), ['nif' => 'B21345673'] + $base)->assertSessionHas('ok');
        $f = FacturaRecibida::first();
        $this->assertSame('T-55', $f->num);
        $this->assertNotNull($f->asiento_id);
        $this->assertNotNull($f->documento_id);
        $this->assertSame('B21345673', Tercero::first()->nif);
        $this->assertSame(0, BandejaDocumento::count());

        // Factura emitida desde Facturación: se revisa y se contabiliza
        $e = app(Facturacion::class)->emitir($this->a, ['serie' => 'A', 'tf' => 'F1', 'fecha' => '2026-11-01', 'receptor_nombre' => 'Hotel Costa SL', 'receptor_nif' => 'B21345673',
            'lineas' => [['d' => 'Servicio', 'q' => 1, 'p' => 500, 'iva' => 21, 'sup' => false]], 'ret_pct' => 0, 'forma_pago' => 'Transferencia', 'pago_dias' => 30], $this->as->id);
        $this->en()->get(route('contabilidad.bandeja', ['revisar' => 'e'.$e->id]))->assertOk()->assertSee('Factura emitida desde AG Cloud');
        $this->en()->post(route('contabilidad.bandeja.revisar', 'e'.$e->id))->assertSessionHas('ok');
        $this->assertNotNull($e->fresh()->asiento_id);
    }

    public function test_eliminar_de_la_bandeja_lo_que_esta_mal(): void
    {
        // Documento subido con fallos: se elimina desde la lista y va a la papelera de Documentos
        $this->en()->post(route('contabilidad.bandeja.subir'), ['archivos' => [UploadedFile::fake()->createWithContent('mal.pdf', '%PDF-1.4 x')]])->assertRedirect();
        $bd = BandejaDocumento::firstOrFail();
        $this->en()->get(route('contabilidad.bandeja'))->assertSee('Eliminar');
        $this->en()->get(route('contabilidad.bandeja', ['revisar' => 'd'.$bd->id]))->assertSee('rev-eliminar');
        $this->en()->delete(route('contabilidad.bandeja.eliminar', 'd'.$bd->id))->assertSessionHas('ok');
        $this->assertSame(0, BandejaDocumento::count());
        $this->assertNotNull(Documento::withoutGlobalScopes()->find($bd->documento_id)->eliminado_at);
        // Factura de gasto sin contabilizar: se elimina; contabilizada, no
        $t = Tercero::create(['cliente_id' => $this->a->id, 'clase' => 'acreedor', 'cuenta' => '410000001', 'nombre' => 'Bar', 'forma_pago' => 'Transferencia', 'pago_dias' => 0]);
        $f = FacturaRecibida::create(['cliente_id' => $this->a->id, 'tercero_id' => $t->id, 'clase' => 'gasto', 'num' => 'X-1', 'fecha' => '2026-11-02', 'concepto' => 'Comida',
            'base' => 10, 'iva_pct' => 10, 'cuota' => 1, 'ret_pct' => 0, 'retencion' => 0, 'total' => 11, 'deducible' => true, 'forma_pago' => 'Transferencia', 'vencimiento' => '2026-11-02']);
        $this->en()->get(route('contabilidad.bandeja'))->assertSee('del-r'.$f->id);
        $this->en()->delete(route('contabilidad.bandeja.eliminar', 'r'.$f->id))->assertSessionHas('ok');
        $this->assertNull(FacturaRecibida::find($f->id));
        // Emitida: no se elimina
        $e = app(Facturacion::class)->emitir($this->a, ['serie' => 'A', 'tf' => 'F1', 'fecha' => '2026-11-01', 'receptor_nombre' => 'Hotel Costa SL', 'receptor_nif' => 'B21345673',
            'lineas' => [['d' => 'Servicio', 'q' => 1, 'p' => 500, 'iva' => 21, 'sup' => false]], 'ret_pct' => 0, 'forma_pago' => 'Transferencia', 'pago_dias' => 30], $this->as->id);
        $this->en()->delete(route('contabilidad.bandeja.eliminar', 'e'.$e->id))->assertSessionHas('aviso');
        $this->assertNotNull(Factura::find($e->id));
    }

    public function test_conciliador_importa_y_concilia(): void
    {
        $conta = app(Contabilidad::class);
        // Asiento manual de una comisión y una factura emitida pendiente de cobro
        $conta->asentar($this->a, '2026-11-03', 'Comisión mantenimiento', [['626', 12, 0], ['572', 0, 12]]);
        $e = app(Facturacion::class)->emitir($this->a, ['serie' => 'A', 'tf' => 'F1', 'fecha' => '2026-11-01', 'receptor_nombre' => 'Hotel Costa SL', 'receptor_nif' => 'B21345673',
            'lineas' => [['d' => 'Servicio', 'q' => 1, 'p' => 1000, 'iva' => 21, 'sup' => false]], 'ret_pct' => 0, 'forma_pago' => 'Transferencia', 'pago_dias' => 30], $this->as->id);
        $csv = "Fecha;Concepto;Importe;Saldo\n04/11/2026;COMISION MANTENIMIENTO;-12,00;988,00\n06/11/2026;TRANSFERENCIA RECIBIDA HOTEL COSTA;1.210,00;2.198,00\n07/11/2026;RECIBO ENDESA;-45,30;2.152,70\n";
        $this->en()->get(route('contabilidad.conciliador'))->assertOk()->assertSee('Importa el extracto');
        $this->en()->post(route('contabilidad.conciliador.importar'), ['cta' => '572000000', 'extracto' => UploadedFile::fake()->createWithContent('extracto.csv', $csv)])
            ->assertSessionHas('ok', fn ($m) => str_contains($m, '3 movimientos leídos') && str_contains($m, '2 conciliados'));
        $this->assertTrue($e->fresh()->cobrada);
        $this->assertNotNull($e->fresh()->cobro_asiento_id);
        // Reimportar no duplica
        $this->en()->post(route('contabilidad.conciliador.importar'), ['cta' => '572000000', 'extracto' => UploadedFile::fake()->createWithContent('extracto.csv', $csv)])->assertSessionHas('ok', fn ($m) => str_contains($m, '0 nuevos'));
        $this->assertSame(3, MovimientoBanco::count());
        // El recibo de Endesa: crear su asiento contra la 628
        $m = MovimientoBanco::where('concepto', 'RECIBO ENDESA')->first();
        $this->en()->get(route('contabilidad.conciliador'))->assertSee('RECIBO ENDESA')->assertSee('410000000');
        $this->en()->post(route('contabilidad.conciliador.asiento', $m), ['contra' => '628'])->assertSessionHas('ok');
        $this->assertNotNull($m->fresh()->apunte_id);
        $as = Asiento::find(Apunte::find($m->fresh()->apunte_id)->asiento_id);
        $this->assertSame([['628000000', 45.3, 0.0], ['572000000', 0.0, 45.3]], $this->apuntes($as));
        $this->en()->post(route('contabilidad.conciliador.deshacer', $m))->assertSessionHas('ok');
        $this->assertNull($m->fresh()->apunte_id);
        // Excel sin columnas reconocibles → error claro
        $this->en()->post(route('contabilidad.conciliador.importar'), ['cta' => '572000000', 'extracto' => UploadedFile::fake()->createWithContent('raro.csv', "a;b\n1;2\n")])->assertSessionHasErrors('extracto');
    }

    public function test_registro_mercantil(): void
    {
        $this->en()->get(route('contabilidad.rm', 'plantillas'))->assertOk()->assertSee('Certificado de aprobación de las cuentas anuales');
        $this->en()->post(route('contabilidad.rm.guardar', 'plantillas'), ['anio' => 2026, 'junta' => '2027-06-20', 'tipo_junta' => 'universal', 'acuerdo' => 'unanimidad'])->assertSessionHas('ok');
        $this->en()->get(route('contabilidad.rm.doc', 'cert'))->assertOk()->assertSee('CERTIFICA')->assertSee('Empresa A SL')->assertSee('20 de junio de 2027');
        $this->en()->get(route('contabilidad.rm', 'plantillas'))->assertSee('Generado');
        $this->en()->get(route('contabilidad.rm', 'legalia'))->assertOk()->assertSee('30/04/2027');
        $this->en()->post(route('contabilidad.rm.guardar', 'legalia'), ['anio' => 2026, 'libros' => ['0', '1'], 'accion' => 'preparado'])->assertSessionHas('ok');
        $this->en()->get(route('contabilidad.rm', 'd2'))->assertOk()->assertSee('30/06/2027')->assertSee('30/07/2027');
        $this->en()->post(route('contabilidad.rm.guardar', 'd2'), ['anio' => 2026, 'docs' => ['0', '1'], 'accion' => 'depositado'])->assertSessionHas('aviso');
        $this->en()->post(route('contabilidad.rm.guardar', 'd2'), ['anio' => 2026, 'docs' => ['0', '1', '2', '3', '4', '5', '6'], 'accion' => 'depositado'])->assertSessionHas('ok');
        $this->en()->get(route('contabilidad.rm', 'd2'))->assertSee('Cuentas depositadas');
        // Un autónomo no deposita cuentas
        $aut = $this->cliente('Lucía Pérez', '29400137Y', ['tipo' => 'Autónomo']);
        $this->actingAs($this->as)->withSession(['cliente_activo' => $aut->id])->get(route('contabilidad.rm', 'd2'))->assertSee('no es una sociedad');
    }
}
