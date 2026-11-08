<?php

namespace Database\Seeders;

use App\Models\Cliente;
use App\Models\Documento;
use App\Models\User;
use App\Services\AlmacenDocumentos;
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
        $mk(['tipo' => 'Autónomo', 'nombre' => 'Manuel Ortiz Pérez', 'nif' => '29331847Q', 'estado' => 'Alta en curso', 'asesor_id' => $admin->id, 'actividad' => 'Fontanería']);
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
