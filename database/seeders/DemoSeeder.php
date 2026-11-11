<?php

namespace Database\Seeders;

use App\Models\Cliente;
use App\Models\Documento;
use App\Models\Expediente;
use App\Models\LaboralMovimiento;
use App\Models\Notificacion;
use App\Models\Oportunidad;
use App\Models\Trabajador;
use App\Models\User;
use App\Services\AlmacenDocumentos;
use App\Services\Facturacion;
use App\Services\Nomina;
use App\Support\Plazos;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * DATOS DE PRUEBA. Nunca en producción.
 * Usuarios (contraseña para todos: Prueba2026):
 *   admin@demo.test (admin_gestoria) · asesor@demo.test (asesor)
 *   cliente@demo.test (cliente_admin de Café Las Colonias SL)
 *   empleado@demo.test (cliente_empleado de Café Las Colonias SL)
 *   otro@demo.test (cliente_admin de Transportes Costa de la Luz SL)
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoSeeder no se puede ejecutar en producción.');
        }
        $pass = 'Prueba2026';
        $admin = User::create(['name' => 'Ana García', 'email' => 'admin@demo.test', 'password' => $pass, 'role' => User::ADMIN, 'cargo' => 'Asesora · Huelva']);
        $ases = User::create(['name' => 'Lucía Martín', 'email' => 'asesor@demo.test', 'password' => $pass, 'role' => User::ASESOR, 'cargo' => 'Asesora laboral']);

        $desp = new Cliente(['tipo' => 'Sociedad', 'nombre' => 'GA AGOPYME SL (demo)', 'nif' => 'B21000005', 'estado' => 'Activo', 'provincia' => 'Huelva', 'asesor_id' => $admin->id, 'fecha_alta' => '2015-01-10']);
        $desp->es_despacho = true;
        $desp->save();

        $mk = fn (array $a) => Cliente::create($a + ['provincia' => 'Huelva', 'fecha_alta' => '2024-03-01', 'cuota_periodicidad' => 'Mensual', 'cuota_iva' => 21]);
        $cafe = $mk(['tipo' => 'Sociedad', 'nombre' => 'Café Las Colonias SL', 'nif' => 'B21890124', 'estado' => 'Activo', 'asesor_id' => $admin->id, 'email' => 'cliente@demo.test', 'telefono' => '600111222', 'domicilio' => 'Pl. de las Colonias 3', 'cp' => '21001', 'municipio' => 'Huelva', 'actividad' => 'Cafetería', 'cuota_importe' => 120]);
        $cafe->setSeccion('bancos', [['iban' => 'ES9121000418450200051332', 'bic' => 'CAIXESBBXXX', 'entidad' => 'CaixaBank', 'titular' => 'Café Las Colonias SL', 'sepa' => 'AGO-00001', 'fSepa' => '2024-03-01', 'principal' => true]]);
        $cafe->setSeccion('acts', [['desc' => 'Cafetería', 'fIni' => '2019-03-01', 'fFin' => '', 'iva' => 'Régimen general', 'irpf' => 'No aplica (Impuesto sobre Sociedades)', 'cnae' => '56.30 · Establecimientos de bebidas', 'iae' => '672.1 · Cafeterías de tres tazas', 'ccc' => '21 1048213 15', 'cccProv' => 'Huelva', 'principal' => true, 'recEq' => false, 'caja' => false]]);
        $cafe->setSeccion('certs', [['tipo' => 'Representante de persona jurídica', 'titular' => 'María López', 'nifT' => '29400137L', 'emisor' => 'FNMT-RCM', 'fEmi' => '2024-12-10', 'fCad' => now()->addDays(30)->toDateString(), 'archivo' => null, 'pass' => '', 'aeat' => true, 'ss' => true, 'dehu' => true]]);
        $cafe->save();
        $trans = $mk(['tipo' => 'Sociedad', 'nombre' => 'Transportes Costa de la Luz SL', 'nif' => 'B21112230', 'estado' => 'Activo', 'asesor_id' => $ases->id, 'email' => 'otro@demo.test', 'actividad' => 'Transporte de mercancías por carretera', 'cuota_importe' => 240]);
        $mk(['tipo' => 'Autónomo', 'nombre' => 'Manuel Ortiz Pérez', 'nif' => '29331847Q', 'estado' => 'Activo', 'asesor_id' => $admin->id, 'actividad' => 'Fontanería']);
        $mk(['tipo' => 'Comunidad de bienes', 'nombre' => 'Hnos. Ruiz Panadería CB', 'nif' => 'E21456785', 'estado' => 'Activo', 'asesor_id' => $ases->id, 'actividad' => 'Panadería y pastelería', 'cuota_importe' => 95.5]);
        $mk(['tipo' => 'Particular', 'nombre' => 'Carmen Vázquez Gil', 'nif' => '48800211F', 'estado' => 'Baja', 'asesor_id' => $admin->id]);

        $cli = User::create(['name' => 'María López', 'email' => 'cliente@demo.test', 'password' => $pass, 'role' => User::CLIENTE_ADMIN, 'cliente_id' => $cafe->id]);
        $emp = User::create(['name' => 'Pedro Sánchez Ruiz', 'email' => 'empleado@demo.test', 'password' => $pass, 'role' => User::CLIENTE_EMPLEADO, 'cliente_id' => $cafe->id]);
        User::create(['name' => 'Jorge Costa', 'email' => 'otro@demo.test', 'password' => $pass, 'role' => User::CLIENTE_ADMIN, 'cliente_id' => $trans->id]);

        // Documentos de prueba (PDF mínimos, guardados cifrados)
        $almacen = app(AlmacenDocumentos::class);
        $doc = function (Cliente $c, string $carpeta, string $tipo, string $nombre, string $fecha, array $extra = []) use ($almacen, $admin) {
            $tmp = tempnam(sys_get_temp_dir(), 'agc');
            file_put_contents($tmp, $this->pdf($nombre));
            $g = $almacen->guardar(new UploadedFile($tmp, $nombre, 'application/pdf', null, true), $c->id);
            @unlink($tmp);

            return Documento::create($extra + ['cliente_id' => $c->id, 'carpeta' => $carpeta, 'tipo' => $tipo, 'nombre' => $nombre, 'nombre_original' => $nombre, 'fecha' => $fecha, 'mime' => 'application/pdf', 'tamano' => $g['tamano'], 'ruta' => $g['ruta'], 'sha256' => $g['sha256'], 'origen' => 'Despacho', 'compartido' => true, 'subido_por' => $admin->id]);
        };
        $doc($cafe, 'fiscal/modelos', 'mod', '20261020_mod_303-3t-2026_aeat.pdf', '2026-10-20');
        $doc($cafe, 'fiscal/modelos', 'mod', '20260720_mod_303-2t-2026_aeat.pdf', '2026-07-20');
        $doc($cafe, 'mercantil/escrituras', 'docu', '20190214_docu_escritura-constitucion.pdf', '2019-02-14', ['compartido' => false]);
        $doc($cafe, 'contable/recibidas', 'fra', '20261103_fra_endesa-octubre.pdf', '2026-11-03', ['origen' => 'Portal del cliente', 'por_revisar' => true, 'subido_por' => $cli->id]);
        $doc($cafe, 'laboral/nominas', 'nom', '20261031_nom_octubre-pedro.pdf', '2026-10-31', ['origen' => 'Portal del cliente', 'compartido' => true, 'por_revisar' => true, 'subido_por' => $emp->id]);
        $doc($trans, 'fiscal/modelos', 'mod', '20261020_mod_303-3t-2026_transportes.pdf', '2026-10-20');

        // Notificaciones y expedientes de prueba (fase 2)
        $hoy = Plazos::hoy();
        foreach ([[$cafe, 0, 2, null], [$cafe, 1, 8, null], [$trans, 3, 6, 1], [$trans, 8, 15, null], [$cafe, 6, 4, 2]] as [$cl, $t, $hace, $abierta]) {
            $tp = config('agcloud.tipos_notificacion')[$t];
            $n = new Notificacion(['cliente_id' => $cl->id, 'organismo' => $tp[0], 'canal' => $tp[1], 'tipo' => $tp[2], 'asunto' => 'Prueba · '.$tp[2], 'referencia' => 'CSV DEMO'.$t.$hace, 'puesta' => $hoy->subDays($hace)->toDateString(), 'plazo_n' => $tp[3], 'plazo_tipo' => $tp[4], 'accion' => $tp[5], 'responsable_id' => $cl->asesor_id]);
            if ($abierta) {
                $n->estado = 'Aceptada';
                $n->acceso = $hoy->subDays($abierta)->toDateString();
            }
            $n->recalcular();
            $n->save();
        }
        Notificacion::aplicarCaducidad();
        foreach ([[$cafe, 'Requerimiento AEAT', 'En curso', 9, 2, 1], [$trans, 'Transferencia DGT', 'Esperando cliente', 20, 1, 0], [$cafe, 'Alta en RETA', 'Presentado', null, 4, 3], [$trans, 'Subvención para pymes', 'Abierto', 40, 0, 0]] as [$cl, $tr, $est, $dias, $hechos, $dok]) {
            $t = config('agcloud.plantillas_expediente')[$tr];
            $e = Expediente::create(['cliente_id' => $cl->id, 'ref' => Expediente::siguienteRef(), 'tramite' => $tr, 'categoria' => $t['cat'], 'responsable_id' => $cl->asesor_id, 'estado' => $est, 'apertura' => $hoy->subDays(12)->toDateString(), 'plazo' => $dias ? $hoy->addDays($dias)->toDateString() : null,
                'pasos' => array_map(fn ($l, $i) => ['l' => $l, 'ok' => $i < $hechos], $t['pasos'], array_keys($t['pasos'])), 'docs' => array_map(fn ($l, $i) => ['l' => $l, 'ok' => $i < $dok], $t['docs'], array_keys($t['docs'])), 'honorarios' => $t['hon']]);
            $e->evento('Expediente abierto (datos de prueba)');
        }

        // Facturas y CRM de prueba (fase 3) · emiten el despacho y Café Las Colonias
        $fac = app(Facturacion::class);
        foreach ([[$cafe, 'Hotel Costa Luz SL', 'B21345678', 'Servicio de catering', 850], [$cafe, 'Academia Columbus SL', 'B21987654', 'Desayunos de empresa', 320], [$desp, 'Transportes Costa de la Luz SL', 'B21112230', 'Cuota de asesoría · noviembre', 240]] as [$em, $rn, $rnif, $d, $p]) {
            $fac->emitir($em, ['serie' => 'A', 'tf' => 'F1', 'fecha' => $hoy->toDateString(), 'receptor_nombre' => $rn, 'receptor_nif' => $rnif, 'receptor_dir' => 'Huelva',
                'lineas' => [['d' => $d, 'q' => 1, 'p' => $p, 'iva' => 21, 'sup' => false]], 'ret_pct' => 0, 'forma_pago' => 'Transferencia', 'pago_dias' => 30], $admin->id);
        }
        foreach ([['Hotel Costa Luz SL', 'Contabilidad y fiscal', 2400, 'Contactado'], ['Clínica Dental Odiel', 'Nóminas (6 trabajadores)', 1800, 'Presupuesto enviado'], ['Frutas Lepe SL', 'Constitución de SL', 350, 'Ganado']] as [$n, $t, $i, $e]) {
            Oportunidad::create(['cliente_id' => $desp->id, 'nombre' => $n, 'titulo' => $t, 'importe' => $i, 'etapa' => $e, 'fecha' => $hoy->addDays(20)->toDateString(), 'origen' => 'Recomendación', 'responsable_id' => $admin->id]);
        }

        // Laboral de prueba: tres trabajadores de Café Las Colonias
        foreach ([['María', 'López', 'Ruiz', '29112233Y', '211234567890', '100', 5, 'Oficial administrativo', 1550, 0, '2019-03-01', 100, ''],
            ['Antonio', 'García', 'Pérez', '29445566T', '211234567891', '402', 7, 'Camarero', 1221, 60, '2026-06-01', 100, '2026-11-30'],
            ['Lucía', 'Romero', 'Díaz', '29778899V', '211234567892', '200', 7, 'Ayudante de cocina', 640, 0, '2023-02-01', 50, '']] as [$n1, $a1, $a2, $nif, $naf, $ct, $g, $cat, $sb, $pl, $alta, $jor, $fin]) {
            $d = Nomina::rellenar(['nom1' => $n1, 'ape1' => $a1, 'ape2' => $a2, 'nombre' => "$n1 $a1 $a2", 'nif' => $nif, 'naf' => $naf, 'contrato' => Nomina::contratoCompleto($ct),
                'grupo' => $g, 'cat' => $cat, 'convCat' => $cat, 'salBase' => $sb, 'plus' => $pl, 'alta' => $alta, 'altaSS' => $alta, 'jor' => $jor, 'horas' => (string) round(40 * $jor / 100), 'fin' => $fin,
                'causa' => $fin ? 'Aumento de la actividad por la temporada de verano y Navidad en la cafetería' : '', 'fnac' => '1990-05-12', 'ccc' => '21123456789'], $cafe);
            $d['sal'] = Nomina::anual($d);
            $t = Trabajador::create(['cliente_id' => $cafe->id, 'estado' => 'Alta', 'alta' => $alta, 'contrato' => $ct, 'datos' => $d]);
            LaboralMovimiento::create(['cliente_id' => $cafe->id, 'trabajador_id' => $t->id, 'fecha' => $alta, 'tipo' => 'Alta', 'detalle' => $d['contrato'], 'estado' => 'Comunicado']);
        }

        $this->command?->info('Datos de prueba creados. Contraseña de todos los usuarios: '.$pass);
    }

    private function pdf(string $txt): string
    {
        $t = preg_replace('/[^A-Za-z0-9 ._-]/', '', $txt);
        $stream = "BT /F1 14 Tf 60 760 Td (AG Cloud - documento de prueba) Tj 0 -24 Td ($t) Tj ET";
        $objs = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>', '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>', '<< /Length '.strlen($stream)." >>\nstream\n$stream\nendstream", '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
        $out = "%PDF-1.4\n";
        $off = [];
        foreach ($objs as $i => $o) {
            $off[] = strlen($out);
            $out .= ($i + 1)." 0 obj\n$o\nendobj\n";
        }
        $x = strlen($out);
        $out .= "xref\n0 ".(count($objs) + 1)."\n0000000000 65535 f \n";
        foreach ($off as $o) {
            $out .= str_pad((string) $o, 10, '0', STR_PAD_LEFT)." 00000 n \n";
        }

        return $out.'trailer << /Size '.(count($objs) + 1)." /Root 1 0 R >>\nstartxref\n$x\n%%EOF";
    }
}
