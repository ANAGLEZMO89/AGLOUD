<?php

namespace App\Console\Commands;

use App\Models\Cliente;
use App\Models\User;
use App\Services\AlmacenDocumentos;
use App\Support\Validacion;
use Illuminate\Console\Command;

/**
 * Puesta en marcha (una sola vez): genera DOCS_KEY si falta, crea la ficha
 * del despacho y el primer usuario administrador. No crea datos de ejemplo.
 */
class Instalar extends Command
{
    protected $signature = 'agcloud:instalar
        {--nombre= : Nombre del administrador}
        {--email= : Correo del administrador}
        {--despacho= : Razón social del despacho}
        {--nif= : NIF del despacho}';

    protected $description = 'Configura AG Cloud por primera vez (clave de documentos, despacho y administrador)';

    public function handle(): int
    {
        // 1) Clave de cifrado de documentos
        if (! config('app.docs_key')) {
            $env = base_path('.env');
            if (! is_file($env) || ! is_writable($env)) {
                $this->error('No puedo escribir en .env. Añade a mano: DOCS_KEY='.AlmacenDocumentos::generarClave());

                return self::FAILURE;
            }
            $k = AlmacenDocumentos::generarClave();
            file_put_contents($env, rtrim(file_get_contents($env))."\nDOCS_KEY=$k\n");
            config(['app.docs_key' => $k]);
            $this->warn('Se ha generado DOCS_KEY en el .env. GUÁRDALA FUERA DEL SERVIDOR (gestor de contraseñas): sin ella los documentos no se pueden recuperar.');
        } else {
            $this->info('DOCS_KEY ya existe: no se toca.');
        }

        // 2) Despacho
        $desp = Cliente::where('es_despacho', true)->first();
        if (! $desp) {
            $nombre = $this->option('despacho') ?: $this->ask('Razón social del despacho', config('agcloud.despacho.nombre'));
            $nif = $this->option('nif') ?: $this->ask('NIF del despacho (puedes dejarlo vacío y completarlo luego)', '');
            $nif = $nif ? Validacion::normalizaNif($nif) : null;
            if ($nif && ! Validacion::nifOk($nif)) {
                $this->error('El NIF del despacho no es válido.');

                return self::FAILURE;
            }
            $desp = new Cliente(['tipo' => 'Sociedad', 'nombre' => $nombre, 'nif' => $nif, 'estado' => 'Activo', 'provincia' => config('agcloud.despacho.provincia'), 'email' => config('agcloud.despacho.email'), 'fecha_alta' => now()]);
            $desp->es_despacho = true;
            $desp->save();
            $this->info('Ficha del despacho creada: '.$desp->nombre);
        }

        // 3) Primer administrador
        if (User::where('role', User::ADMIN)->exists()) {
            $this->info('Ya hay un administrador. Instalación completa.');

            return self::SUCCESS;
        }
        $nombre = $this->option('nombre') ?: $this->ask('Nombre y apellidos del administrador');
        $email = mb_strtolower(trim((string) ($this->option('email') ?: $this->ask('Correo del administrador'))));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Correo no válido.');

            return self::FAILURE;
        }
        $pass = Validacion::generaContrasena();
        User::create(['name' => $nombre, 'email' => $email, 'password' => $pass, 'role' => User::ADMIN, 'activo' => true, 'must_change_password' => true]);
        $desp->asesor_id = User::where('email', $email)->value('id');
        $desp->save();

        $this->newLine();
        $this->info('Administrador creado.');
        $this->line('  Usuario:                '.$email);
        $this->line('  Contraseña provisional: '.$pass);
        $this->warn('Entra y cámbiala. Después activa la verificación en dos pasos en «Mi cuenta».');

        return self::SUCCESS;
    }
}
