#!/usr/bin/env bash
#
# Überträgt den lokalen Stand auf eine Hallen-Installation und leert die Caches.
#
#   scripts/deploy.sh                    → ipa.vcontrol.eu
#   scripts/deploy.sh halle2.vcontrol.eu → andere Installation, gleicher Code
#
# Nicht enthalten, weil bewusst von Hand: `composer install` nach Änderungen an
# composer.json und `php artisan migrate --force` bei neuen Migrationen. Das
# Skript zeigt offene Migrationen am Ende an – und Dateien, die auf dem Server
# liegen, aber nicht mehr im Repo (umbenannt oder entfernt). Die löscht es nicht.

set -euo pipefail

TARGET="${1:-ipa.vcontrol.eu}"
HOST="allinkl-eventmanager"
DIR="/www/htdocs/w0219ff1/${TARGET}"

cd "$(dirname "$0")/.."

EXCLUDES=(
    --exclude .git/ --exclude vendor/ --exclude node_modules/
    --exclude .env --exclude storage/ --exclude bootstrap/cache/
    --exclude database/database.sqlite
    --exclude public/css/filament/ --exclude public/js/filament/ --exclude public/fonts/filament/
    --exclude .phpunit.result.cache --exclude .phpunit.cache/
)

ssh "$HOST" "test -f ${DIR}/artisan" || { echo "Keine Laravel-Installation unter ${DIR}" >&2; exit 1; }

rsync -rlpt --itemize-changes "${EXCLUDES[@]}" ./ "${HOST}:${DIR}/" | grep -v '^\.d' || true

ssh "$HOST" "cd ${DIR} \
    && php artisan optimize:clear >/dev/null \
    && php artisan filament:optimize >/dev/null \
    && echo 'Caches geleert.' \
    && pending=\$(php artisan migrate:status 2>/dev/null | grep -c Pending || true) \
    && if [ \"\$pending\" -gt 0 ]; then echo \"Offene Migrationen: \$pending – php artisan migrate --force\"; fi"

# Probelauf mit --delete: zeigt nur an, was auf dem Server übrig ist
orphans=$(rsync -rlptn --delete --itemize-changes "${EXCLUDES[@]}" ./ "${HOST}:${DIR}/" 2>/dev/null | sed -n 's/^\*deleting //p' || true)
if [ -n "$orphans" ]; then
    echo "Auf dem Server, aber nicht im Repo (nicht gelöscht):"
    echo "$orphans" | sed 's/^/  /'
fi
