#!/usr/bin/env bash
# AG Cloud · primera instalación en Hostinger (ejecutar por SSH dentro de la carpeta del proyecto)
set -euo pipefail
cd "$(dirname "$0")/.."

PHP=${PHP:-php}
echo "== PHP: $($PHP -r 'echo PHP_VERSION;')"
$PHP -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);' || { echo "ERROR: hace falta PHP 8.3 o superior (ver guía, paso 2)."; exit 1; }
for ext in sodium zip pdo_mysql mbstring openssl fileinfo; do
  $PHP -m | grep -qi "^$ext$" || { echo "ERROR: falta la extensión PHP «$ext» (hPanel › Configuración de PHP › Extensiones)."; exit 1; }
done

[ -f .env ] || { cp .env.example .env; echo "== Creado .env a partir de .env.example: RELLÉNALO (guía, paso 7) y vuelve a ejecutar este script."; exit 0; }
grep -q '^DB_DATABASE=.\+' .env || { echo "ERROR: rellena DB_DATABASE, DB_USERNAME y DB_PASSWORD en .env"; exit 1; }

echo "== Dependencias"
composer install --no-dev --optimize-autoloader --no-interaction

grep -q '^APP_KEY=base64' .env || $PHP artisan key:generate --force

echo "== Base de datos"
$PHP artisan migrate --force

echo "== Despacho y administrador"
$PHP artisan agcloud:instalar

chmod -R ug+rwX storage bootstrap/cache
chmod 600 .env
$PHP artisan optimize
echo
echo "== Listo. Guarda AHORA en tu gestor de contraseñas estas tres líneas del .env:"
grep -E '^(APP_KEY|DOCS_KEY|BACKUP_ARCHIVE_PASSWORD)=' .env
