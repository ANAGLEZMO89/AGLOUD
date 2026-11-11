<?php

/*
|--------------------------------------------------------------------------
| AG Cloud · instalador web de un solo uso (para Hostinger sin SSH)
|--------------------------------------------------------------------------
| 1. Pon en el .env una línea  INSTALL_TOKEN=una-clave-larga-que-inventes
| 2. Abre https://app.gestoriaagopyme.es/instalar.php y escribe esa clave.
| 3. Al terminar, este archivo se borra solo. Si ya hay un administrador,
|    se niega a funcionar.
*/

declare(strict_types=1);
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Schema;

$base = dirname(__DIR__);
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

if (empty($_SERVER['HTTPS']) && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') !== 'https' && ! in_array(strtok((string) ($_SERVER['HTTP_HOST'] ?? ''), ':'), ['127.0.0.1', 'localhost'], true)) {
    header('Location: https://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'], true, 301);
    exit;
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function pagina(string $html): never
{
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Instalar AG Cloud</title>'
        .'<link rel="stylesheet" href="css/agcloud.css"></head><body><div class="auth"><div class="auth-card" style="width:min(560px,100%)">'
        .'<div class="hd"><img src="img/logo-ga-128.png" alt="GA"><div><b>AG CLOUD</b><small>Instalación</small></div></div>'.$html.'</div></div></body></html>';
    exit;
}

// Comprobaciones previas
$err = [];
if (version_compare(PHP_VERSION, '8.3.0', '<')) {
    $err[] = 'Hace falta PHP 8.3 o superior (ahora: '.PHP_VERSION.'). hPanel › Avanzado › Configuración de PHP.';
}
foreach (['sodium', 'zip', 'pdo_mysql', 'mbstring', 'openssl', 'fileinfo'] as $ext) {
    if (! extension_loaded($ext)) {
        $err[] = 'Falta la extensión PHP «'.$ext.'» (hPanel › Configuración de PHP › Extensiones).';
    }
}
if (! is_file($base.'/vendor/autoload.php')) {
    $err[] = 'Falta la carpeta «vendor». Sube el ZIP completo de AG Cloud.';
}
if (! is_file($base.'/.env')) {
    $err[] = 'Falta el archivo .env. Copia «.env.example» como «.env» y rellénalo (guía, paso 7).';
} elseif (! is_writable($base.'/.env')) {
    $err[] = 'El archivo .env no tiene permiso de escritura (ponle permisos 644 o 664 en el Administrador de archivos).';
}
foreach (['storage', 'bootstrap/cache'] as $d) {
    if (! is_writable($base.'/'.$d)) {
        $err[] = 'La carpeta «'.$d.'» no tiene permiso de escritura (755).';
    }
}
if ($err) {
    pagina('<h1>Antes de instalar</h1><div class="ferr">'.implode('<br>', array_map('h', $err)).'</div><span class="hint muted">Corrígelo y recarga esta página.</span>');
}

$env = (string) file_get_contents($base.'/.env');
preg_match('/^INSTALL_TOKEN=(.*)$/m', $env, $m);
$token = trim($m[1] ?? '', " \t\"'");
if (strlen($token) < 16) {
    pagina('<h1>Falta la clave de instalación</h1><span style="font-size:13.5px;line-height:1.5">Añade al final del archivo <b>.env</b> una línea así (inventa tú la clave, mínimo 16 caracteres):</span><div class="codes" style="grid-template-columns:1fr">INSTALL_TOKEN=mi-clave-de-instalacion-2026</div><span class="hint muted">Guarda el .env y recarga esta página.</span>');
}

require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

try {
    $yaInstalado = Schema::hasTable('users')
        && User::where('role', User::ADMIN)->exists();
} catch (Throwable $e) {
    pagina('<h1>No conecto con la base de datos</h1><div class="ferr">'.h($e->getMessage()).'</div><span class="hint muted">Revisa DB_DATABASE, DB_USERNAME y DB_PASSWORD en el .env (con el prefijo u123…_ de Hostinger) y recarga.</span>');
}
if ($yaInstalado) {
    @unlink(__FILE__);
    pagina('<h1>AG Cloud ya está instalado</h1><span style="font-size:13.5px">Este instalador ya no se puede usar.</span><a class="btn lime lg" href="/login">Ir al acceso</a>');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    pagina('<h1>Instalar AG Cloud</h1>
<form method="POST" style="display:flex;flex-direction:column;gap:14px">
<label>Clave de instalación (INSTALL_TOKEN del .env)<input class="inp" type="password" name="token" required></label>
<label>Razón social del despacho<input class="inp" name="despacho" value="GA AGOPYME SL" required></label>
<label>NIF del despacho (opcional)<input class="inp mono" name="nif"></label>
<label>Tu nombre y apellidos (administradora)<input class="inp" name="nombre" required></label>
<label>Tu correo (será tu usuario)<input class="inp" type="email" name="email" required></label>
<button class="btn lime lg" type="submit">Instalar</button>
</form>');
}

if (! hash_equals($token, (string) ($_POST['token'] ?? ''))) {
    sleep(2);
    pagina('<h1>Clave incorrecta</h1><a class="btn" href="instalar.php">Volver</a>');
}

@set_time_limit(300);
$log = [];
$run = function (string $cmd, array $args = []) use ($kernel, &$log) {
    $code = $kernel->call($cmd, $args + ['--no-interaction' => true]);
    $log[] = '$ php artisan '.$cmd."\n".trim($kernel->output());
    if ($code !== 0) {
        throw new RuntimeException('Falló «'.$cmd.'».');
    }
};

try {
    if (! preg_match('/^APP_KEY=base64:.+$/m', $env)) {
        $run('key:generate', ['--force' => true]);
    }
    $run('migrate', ['--force' => true]);
    $run('agcloud:instalar', [
        '--despacho' => trim((string) $_POST['despacho']),
        '--nif' => trim((string) ($_POST['nif'] ?? '')),
        '--nombre' => trim((string) $_POST['nombre']),
        '--email' => trim((string) $_POST['email']),
    ]);
} catch (Throwable $e) {
    pagina('<h1>La instalación se ha detenido</h1><div class="ferr">'.h($e->getMessage()).'</div><pre style="white-space:pre-wrap;font:12px var(--mono);background:#F4F4F0;padding:12px;border-radius:10px;max-height:300px;overflow:auto">'.h(implode("\n\n", $log)).'</pre>');
}

$out = implode("\n", $log);
preg_match('/Contraseña provisional:\s*(\S+)/u', $out, $pm);
$env = (string) file_get_contents($base.'/.env');
preg_match_all('/^(APP_KEY|DOCS_KEY|BACKUP_ARCHIVE_PASSWORD)=.*$/m', $env, $claves);
// Retira la clave de instalación del .env y borra este archivo
file_put_contents($base.'/.env', preg_replace('/^INSTALL_TOKEN=.*\R?/m', '', $env));
@unlink(__FILE__);

pagina('<h1>¡Instalado!</h1>
<div class="msg">Usuario: <b>'.h((string) $_POST['email']).'</b><br>Contraseña provisional: <b class="mono">'.h($pm[1] ?? '(ver abajo)').'</b></div>
<div class="ferr" style="font-weight:500">🔐 Guarda AHORA estas líneas en tu gestor de contraseñas. Sin ellas, los documentos y las copias no se pueden recuperar:</div>
<div class="codes" style="grid-template-columns:1fr;word-break:break-all">'.implode('<br>', array_map('h', $claves[0])).'</div>'
.(in_array('BACKUP_ARCHIVE_PASSWORD=', array_map('trim', $claves[0]), true) ? '<div class="msg warn">BACKUP_ARCHIVE_PASSWORD está vacía: rellénala en el .env para que funcionen las copias.</div>' : '')
.'<span class="hint muted">Este instalador se ha borrado solo. Siguiente: tarea programada (guía, paso 9) y entrar.</span>
<a class="btn lime lg" href="/login">Entrar en AG Cloud</a>');
