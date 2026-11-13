#!/usr/bin/env bash
# AG Cloud · actualizar a la última versión (ejecutar por SSH dentro de la carpeta del proyecto)
set -euo pipefail
cd "$(dirname "$0")/.."
PHP=${PHP:-php}
$PHP artisan agcloud:backup --sin-drive || { echo "La copia previa ha fallado: no actualizo."; exit 1; }
$PHP artisan down --retry=60 || true
git pull --ff-only
composer install --no-dev --optimize-autoloader --no-interaction
$PHP artisan migrate --force
$PHP artisan optimize:clear
$PHP artisan optimize
$PHP artisan up
echo "Actualizado a: $(git log -1 --format='%h · %s · %cd' --date=short)"
