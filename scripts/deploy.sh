#!/usr/bin/env bash
#
# Überträgt den lokalen Stand auf eine Hallen-Installation und leert die Caches.
#
#   scripts/deploy.sh                    → ipa.vcontrol.eu
#   scripts/deploy.sh halle2.vcontrol.eu → andere Installation, gleicher Code
#
# Nicht enthalten, weil bewusst von Hand: `composer install` nach Änderungen an
# composer.json und `php artisan migrate --force` bei neuen Migrationen. Das
# Skript zeigt offene Migrationen am Ende an.

set -euo pipefail

TARGET="${1:-ipa.vcontrol.eu}"
HOST="allinkl-eventmanager"
DIR="/www/htdocs/w0219ff1/${TARGET}"

cd "$(dirname "$0")/.."

ssh "$HOST" "test -f ${DIR}/artisan" || { echo "Keine Laravel-Installation unter ${DIR}" >&2; exit 1; }

rsync -rlpt --itemize-changes \
    --exclude .git/ --exclude vendor/ --exclude node_modules/ \
    --exclude .env --exclude storage/ --exclude bootstrap/cache/ \
    --exclude database/database.sqlite \
    --exclude public/css/filament/ --exclude public/js/filament/ --exclude public/fonts/filament/ \
    ./ "${HOST}:${DIR}/" | grep -v '^\.d' || true

ssh "$HOST" "cd ${DIR} \
    && php artisan optimize:clear >/dev/null \
    && php artisan filament:optimize >/dev/null \
    && echo 'Caches geleert.' \
    && pending=\$(php artisan migrate:status 2>/dev/null | grep -c Pending || true) \
    && if [ \"\$pending\" -gt 0 ]; then echo \"Offene Migrationen: \$pending – php artisan migrate --force\"; fi"
