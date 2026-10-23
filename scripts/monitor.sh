#!/usr/bin/env bash
# Ensures the Deluxe Payment Platform plugin survives Invoice Ninja updates.
# Set IN_APP_DIR / IN_USER for your install.

set -euo pipefail

DIR="${IN_APP_DIR:-/var/www/invoiceninja}"
IN_USER="${IN_USER:-www-data}"
SCRIPTS_DIR="$DIR/packages/Deluxe/scripts"
COMPOSER_JSON="$DIR/composer.json"
GATEWAY_PHP="$DIR/app/Models/Gateway.php"
LOG="$SCRIPTS_DIR/monitor.log"
FIXED=0

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" >> "$LOG"; }

# --- 1. composer.json: path repository + require ----------------------------

if ! grep -q '"chooka321/deluxe-payment-platform"' "$COMPOSER_JSON"; then
    log "MISSING: chooka321/deluxe-payment-platform require — re-applying"
    sed -i 's|"require": {|"require": {\n        "chooka321/deluxe-payment-platform": "*",|' "$COMPOSER_JSON"
    FIXED=1
fi

if ! grep -q '"url": "packages/Deluxe"' "$COMPOSER_JSON"; then
    log "MISSING: packages/Deluxe path repository — re-applying"
    sed -i 's|"repositories": \[|"repositories": [\n        {\n            "type": "path",\n            "url": "packages/Deluxe"\n        },|' "$COMPOSER_JSON"
    FIXED=1
fi

if [ "$FIXED" -eq 1 ]; then
    chown "$IN_USER:$IN_USER" "$COMPOSER_JSON"
    log "Running composer update chooka321/deluxe-payment-platform"
    cd "$DIR" && sudo -u "$IN_USER" composer update chooka321/deluxe-payment-platform --no-interaction --quiet 2>>"$LOG"
    log "Composer update complete"
    FIXED=2
fi

# --- 2. Gateway.php: getMethods() case 1000 -----------------------------------

if ! grep -q 'case 1000:' "$GATEWAY_PHP"; then
    log "MISSING: case 1000 in Gateway::getMethods() — re-applying"
    sed -i '/^            default:$/i\            case 1000:\n                return [\n                    GatewayType::CREDIT_CARD   => ['"'"'refund'"'"' => true,  '"'"'token_billing'"'"' => false, '"'"'webhooks'"'"' => ['"'"'TRANSACTION'"'"']],\n                    GatewayType::BANK_TRANSFER => ['"'"'refund'"'"' => false, '"'"'token_billing'"'"' => false, '"'"'webhooks'"'"' => ['"'"'TRANSACTION'"'"', '"'"'ACH REJECT'"'"']],\n                ]; \/\/ Deluxe Payment Platform' "$GATEWAY_PHP"
    chown "$IN_USER:$IN_USER" "$GATEWAY_PHP"
    log "Gateway.php case 1000 re-applied"
    FIXED=2
fi

# --- 3. Clear cache if anything was fixed ------------------------------------

if [ "$FIXED" -eq 2 ]; then
    cd "$DIR" && sudo -u "$IN_USER" php artisan optimize:clear --quiet 2>>"$LOG"
    log "Cache cleared after recovery"
fi

exit 0
