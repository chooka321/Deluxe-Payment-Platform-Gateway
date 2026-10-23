#!/usr/bin/env bash
# Clears Laravel cache whenever the Deluxe gateway settings are saved.
# Looks up the Deluxe company_gateways row by gateway_key
# (no hard-coded row id, since production's row id is created at user setup time).

DIR="${IN_APP_DIR:-/var/www/invoiceninja}"
IN_USER="${IN_USER:-www-data}"
STATE_FILE="$DIR/packages/Deluxe/scripts/.gateway_updated_at"
LOG="$DIR/packages/Deluxe/scripts/monitor.log"
ENV="$DIR/.env"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] [testmode-watch] $*" >> "$LOG"; }

DB_PW=$(grep '^DB_PASSWORD=' "$ENV" | cut -d'=' -f2-)
DB_PW="${DB_PW%\"}"; DB_PW="${DB_PW#\"}"
DB_USER=$(grep '^DB_USERNAME=' "$ENV" | cut -d'=' -f2-)
DB_USER="${DB_USER%\"}"; DB_USER="${DB_USER#\"}"
DB_NAME=$(grep '^DB_DATABASE=' "$ENV" | cut -d'=' -f2-)
DB_NAME="${DB_NAME%\"}"; DB_NAME="${DB_NAME#\"}"

MYCNF=$(mktemp); chmod 600 "$MYCNF"
cat > "$MYCNF" <<MYCNFEOF
[client]
host=127.0.0.1
user=$DB_USER
password=$DB_PW
MYCNFEOF

CURRENT=$(mysql --defaults-extra-file="$MYCNF" -D "$DB_NAME" -sN \
    -e "SELECT cg.updated_at FROM company_gateways cg JOIN gateways g ON g.\`key\`=cg.gateway_key WHERE g.\`key\`='deluxepaymentplatform' AND cg.is_deleted=0 LIMIT 1;" 2>/dev/null)

rm -f "$MYCNF"

if [[ -z "$CURRENT" ]]; then
    # No Deluxe company_gateway row yet — nothing to watch. Silently exit.
    exit 0
fi

if [[ ! -f "$STATE_FILE" ]]; then
    echo "$CURRENT" > "$STATE_FILE"
    log "Initialized: gateway last updated $CURRENT"
    exit 0
fi

LAST=$(cat "$STATE_FILE")

if [[ "$CURRENT" != "$LAST" ]]; then
    log "Gateway settings changed ($LAST -> $CURRENT) — clearing cache"
    sudo -u "$IN_USER" php "$DIR/artisan" cache:clear --quiet 2>>"$LOG"
    echo "$CURRENT" > "$STATE_FILE"
    log "Done"
fi
