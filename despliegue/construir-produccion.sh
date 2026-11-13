#!/usr/bin/env bash
# Construye la rama «produccion» (app + vendor sin dependencias de desarrollo),
# lista para que Hostinger la descargue con su despliegue automático desde GitHub.
# Uso: despliegue/construir-produccion.sh <carpeta-de-la-rama-produccion>
set -euo pipefail
SRC="$(cd "$(dirname "$0")/.." && pwd)"
DST="${1:?Indica la carpeta de trabajo de la rama produccion}"
VER="$(git -C "$SRC" rev-parse --short HEAD)-$(date -u +%Y%m%d%H%M)"

# Limpia la carpeta destino (salvo .git)
find "$DST" -mindepth 1 -maxdepth 1 ! -name .git -exec rm -rf {} +
# Copia los archivos versionados de la app
git -C "$SRC" ls-files | grep -vE '^(tests/|documentacion/|\.github/|phpunit\.xml)' | (cd "$SRC" && tar -cf - -T -) | (cd "$DST" && tar -xf -)
cd "$DST"
mv htaccess-raiz-sin-ssh.txt .htaccess
cp "$SRC/documentacion/SUBIR-SIN-SSH.md" LEEME-SUBIR-A-HOSTINGER.md
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction --quiet
find vendor -name .git -type d -prune -exec rm -rf {} +
find vendor -type d \( -name tests -o -name Tests -o -name docs -o -name .github \) -prune -exec rm -rf {} +
echo "$VER" > VERSION
# En producción, vendor SÍ va en git; .env, datos y cachés nunca
cat > .gitignore <<'GI'
/.env
/.env.*
!/.env.example
/storage/app/privado/
/storage/app/backups/
/storage/app/version-aplicada
/storage/logs/*.log
/storage/framework/cache/data/*
/storage/framework/sessions/*
/storage/framework/views/*
/bootstrap/cache/*.php
/database/*.sqlite
!/storage/**/.gitignore
!/bootstrap/cache/.gitignore
GI
echo "Versión construida: $VER"
