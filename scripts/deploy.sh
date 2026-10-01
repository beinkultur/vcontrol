#!/usr/bin/env bash
#
# Überträgt den lokalen Stand auf eine Hallen-Installation und leert die Caches.
#
#   scripts/deploy.sh                       → ipa.vcontrol.eu
#   scripts/deploy.sh halle2.vcontrol.eu    → andere Installation, gleicher Code
#   scripts/deploy.sh --dry-run [halle]     → nur anzeigen, auf dem Server ändert sich nichts
#
# Was im Repo gelöscht oder umbenannt wurde, löscht das Skript auch auf dem
# Server – aber nur in den Code-Ordnern (CLEAN_DIRS). Nie angefasst werden das
# Wurzelverzeichnis (u. a. .env), public/ (dort liegen auch Dateien, die nur der
# Server kennt), storage/ (Uploads, Fotos, Logs), vendor/ und die übrigen
# EXCLUDES. Reste außerhalb der Code-Ordner zeigt es nur an.
#
# Sollen mehr als MAX_DELETE Dateien weg, bricht es ab, bevor etwas übertragen
# wird – meist ist dann der lokale Ordner unvollständig, etwa mitten im
# Nextcloud-Abgleich. Gelöscht wird bewusst per Liste statt mit rsync --delete:
# Der Mac bringt openrsync mit, der Server GNU rsync, und Schutzregeln sollen
# nicht davon abhängen, wie die beiden Filter austauschen.
#
# Nicht enthalten, weil bewusst von Hand: `composer install` nach Änderungen an
# composer.json und `php artisan migrate --force` bei neuen Migrationen. Das
# Skript zeigt offene Migrationen am Ende an.

set -euo pipefail

DRY_RUN=0
if [ "${1:-}" = "--dry-run" ]; then
    DRY_RUN=1
    shift
fi

TARGET="${1:-ipa.vcontrol.eu}"
HOST="allinkl-eventmanager"
DIR="/www/htdocs/w0219ff1/${TARGET}"
MAX_DELETE="${MAX_DELETE:-20}"

# Nur in diesen Ordnern werden Reste gelöscht
CLEAN_DIRS='app|config|database|resources|routes|tests'

cd "$(dirname "$0")/.."

EXCLUDES=(
    --exclude .git/ --exclude vendor/ --exclude node_modules/
    --exclude .env --exclude '.env.*' --exclude auth.json --exclude .DS_Store
    --exclude storage/ --exclude bootstrap/cache/
    --exclude database/database.sqlite
    --exclude public/css/filament/ --exclude public/js/filament/ --exclude public/fonts/filament/
    --exclude .phpunit.result.cache --exclude .phpunit.cache/
)

ssh "$HOST" "test -f ${DIR}/artisan" || { echo "Keine Laravel-Installation unter ${DIR}" >&2; exit 1; }

# Was auf dem Server liegt, aber nicht im Repo (Probelauf, ändert nichts).
# Was unter EXCLUDES fällt, taucht hier gar nicht erst auf.
leftovers=$(rsync -rlptn --delete --itemize-changes "${EXCLUDES[@]}" ./ "${HOST}:${DIR}/" | sed -n 's/^\*deleting  *//p')
doomed=$(printf '%s\n' "$leftovers" | grep -E "^(${CLEAN_DIRS})/." | grep -v -E '(^|/)\.\.(/|$)' || true)
kept=$(printf '%s\n' "$leftovers" | grep -v -E "^(${CLEAN_DIRS})/." | grep . || true)
count=$(printf '%s' "$doomed" | grep -c . || true)

report_kept() {
    if [ -n "$kept" ]; then
        echo "Auf dem Server, aber nicht im Repo (außerhalb der Code-Ordner, nicht gelöscht):"
        echo "$kept" | sed 's/^/  /'
    fi
}

if [ "$DRY_RUN" = 1 ]; then
    echo "Probelauf für ${TARGET} – auf dem Server ändert sich nichts."
    rsync -rlptn --itemize-changes "${EXCLUDES[@]}" ./ "${HOST}:${DIR}/" | grep -v '^\.d' || true
    if [ -n "$doomed" ]; then
        echo "Würde gelöscht (nicht mehr im Repo):"
        echo "$doomed" | sed 's/^/  /'
        if [ "$count" -gt "$MAX_DELETE" ]; then
            echo "Achtung: ${count} Dateien, mehr als ${MAX_DELETE} – ein echter Lauf bricht ab."
        fi
    fi
    report_kept
    exit 0
fi

if [ "$count" -gt "$MAX_DELETE" ]; then
    echo "Abbruch, nichts übertragen: ${count} Dateien würden auf dem Server gelöscht (Grenze ${MAX_DELETE})." >&2
    echo "$doomed" | head -30 | sed 's/^/  /' >&2
    echo "Ist der lokale Ordner vollständig? Dann: MAX_DELETE=${count} scripts/deploy.sh ${TARGET}" >&2
    exit 1
fi

rsync -rlpt --itemize-changes "${EXCLUDES[@]}" ./ "${HOST}:${DIR}/" | grep -v '^\.d' || true

if [ -n "$doomed" ]; then
    # Erst die Dateien, dann leere Ordner von innen nach außen
    files=$(printf '%s\n' "$doomed" | grep -v '/$' || true)
    dirs=$(printf '%s\n' "$doomed" | grep '/$' | awk '{ print length($0) "\t" $0 }' | sort -rn | cut -f2- || true)
    if [ -n "$files" ]; then
        printf '%s\n' "$files" | tr '\n' '\0' | ssh "$HOST" "cd ${DIR} && xargs -0 -r rm -f --"
    fi
    if [ -n "$dirs" ]; then
        printf '%s\n' "$dirs" | tr '\n' '\0' | ssh "$HOST" "cd ${DIR} && xargs -0 -r rmdir --ignore-fail-on-non-empty --"
    fi
    echo "Gelöscht (nicht mehr im Repo):"
    echo "$doomed" | sed 's/^/  /'
fi

ssh "$HOST" "cd ${DIR} \
    && php artisan optimize:clear >/dev/null \
    && php artisan filament:optimize >/dev/null \
    && echo 'Caches geleert.' \
    && pending=\$(php artisan migrate:status 2>/dev/null | grep -c Pending || true) \
    && if [ \"\$pending\" -gt 0 ]; then echo \"Offene Migrationen: \$pending – php artisan migrate --force\"; fi"

report_kept
