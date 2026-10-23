#!/bin/bash
# Self-heals the Deluxe package registration in Invoice Ninja.
#
# IN's self-update overwrites vendor/composer/installed.json with the vanilla
# release, which drops the chooka321/deluxe-payment-platform entry for our
# local path-repo package (packages/Deluxe). When that happens the
# /deluxe/webhook route disappears and payment webhooks are silently dropped.
#
# We can't run `composer require` to fix it: the vanilla IN composer.json
# has a VCS entry for invoiceninja/einvoice that composer tries to git-clone
# from GitHub via SSH (auth we don't have on this server). So the guard
# does the equivalent surgery offline:
#   1. Rebuild the installed.json entry from packages/Deluxe/composer.json
#   2. Insert into vendor/composer/installed.json
#   3. Ensure vendor/chooka321/deluxe-payment-platform symlinks to packages/Deluxe
#   4. composer dump-autoload -o (offline, reads installed.json)
#   5. artisan package:discover (regenerates Laravel package manifest)
#   6. Clear Laravel caches, verify route, email on repair or failure

set -o pipefail

APP="${IN_APP_DIR:-/var/www/invoiceninja}"
IN_USER="${IN_USER:-www-data}"
PACKAGE=chooka321/deluxe-payment-platform
PKG_DIR=$APP/packages/Deluxe
INSTALLED_JSON=$APP/vendor/composer/installed.json
VENDOR_SYMLINK=$APP/vendor/chooka321/deluxe-payment-platform
LOG="${DELUXE_GUARD_LOG:-/var/log/deluxe-guard.log}"
MAIL_TO="${DELUXE_GUARD_MAIL_TO:-root@localhost}"
STAMP=$(date '+%Y-%m-%d %H:%M:%S %Z')

log() { echo "[$STAMP] $*" >> "$LOG"; }

if [ ! -f "$INSTALLED_JSON" ]; then
  log "FATAL vendor/composer/installed.json missing — not attempting repair"
  echo "Deluxe guard: $INSTALLED_JSON is missing on $(hostname). App tree may be broken." \
    | mail -s "Deluxe guard: installed.json missing on $(hostname)" "$MAIL_TO"
  exit 2
fi

if [ ! -f "$PKG_DIR/composer.json" ]; then
  log "FATAL packages/Deluxe/composer.json missing — package source is gone"
  echo "Deluxe guard: packages/Deluxe/composer.json missing on $(hostname). Package source is gone." \
    | mail -s "Deluxe guard: Deluxe package source missing on $(hostname)" "$MAIL_TO"
  exit 2
fi

# Fast check: package already listed? Stay silent if so.
# Also require the autoloader to know the namespace: IN's updater can re-dump
# autoload after we patch installed.json (2026-10-23), leaving the provider
# class unloadable and the whole app 500ing.
if grep -q "\"$PACKAGE\"" "$INSTALLED_JSON" && grep -q 'Chooka321' "$APP/vendor/composer/autoload_static.php"; then
  exit 0
fi

log "MISSING package $PACKAGE in installed.json — running offline repair"

# 1. Ensure vendor symlink to packages/Deluxe
mkdir -p "$(dirname "$VENDOR_SYMLINK")"
if [ ! -e "$VENDOR_SYMLINK" ]; then
  ln -sfn ../../packages/Deluxe "$VENDOR_SYMLINK"
  chown -h "$IN_USER:$IN_USER" "$VENDOR_SYMLINK" "$(dirname "$VENDOR_SYMLINK")"
  log "created vendor symlink"
fi

# 2. Backup + patch installed.json
cp "$INSTALLED_JSON" "${INSTALLED_JSON}.bak.$(date +%Y%m%d_%H%M%S)"

PATCH_OUT=$(python3 <<PY 2>&1
import json, hashlib, sys

app = '$APP'
pkg_dir = f'{app}/packages/Deluxe'
installed_path = f'{app}/vendor/composer/installed.json'
package = '$PACKAGE'

manifest = json.load(open(f'{pkg_dir}/composer.json'))
sha = hashlib.sha1(open(f'{pkg_dir}/composer.json', 'rb').read()).hexdigest()

entry = {
    'name': manifest['name'],
    'version': 'dev-main',
    'version_normalized': 'dev-main',
    'dist': {'type': 'path', 'url': 'packages/Deluxe', 'reference': sha},
    'type': manifest.get('type', 'library'),
    'extra': manifest.get('extra', {}),
    'installation-source': 'dist',
    'autoload': manifest.get('autoload', {}),
    'description': manifest.get('description', ''),
    'transport-options': {'relative': True},
    'install-path': f'../chooka321/deluxe-payment-platform',
}

installed = json.load(open(installed_path))
if any(p.get('name') == package for p in installed.get('packages', [])):
    print('already-present')
    sys.exit(0)

installed['packages'].insert(0, entry)
json.dump(installed, open(installed_path, 'w'), indent=4)
print('patched')
PY
)
PATCH_RC=$?
log "installed.json patch: $PATCH_OUT (rc=$PATCH_RC)"

if [ $PATCH_RC -ne 0 ]; then
  {
    echo "Deluxe guard: Python patch of installed.json FAILED on $(hostname) at $STAMP"
    echo "Output: $PATCH_OUT"
    echo "Log: $LOG"
  } | mail -s "Deluxe guard: patch FAILED on $(hostname)" "$MAIL_TO"
  exit 1
fi

chown "$IN_USER:$IN_USER" "$INSTALLED_JSON"

# 3. Regenerate autoload (offline — reads installed.json)
sudo -u "$IN_USER" php -d memory_limit=-1 /usr/local/bin/composer \
  dump-autoload -o --no-scripts -d "$APP" >> "$LOG" 2>&1

# 4. Regenerate Laravel package manifest (this was the missing piece)
sudo -u "$IN_USER" php "$APP/artisan" package:discover >> "$LOG" 2>&1

# 5. Clear Laravel caches
for cmd in config:clear route:clear cache:clear view:clear; do
  sudo -u "$IN_USER" php "$APP/artisan" $cmd >> "$LOG" 2>&1
done

# 6. Verify
ROUTE_HIT=$(sudo -u "$IN_USER" php "$APP/artisan" route:list 2>/dev/null | grep -c "deluxe/webhook")
log "post-repair route:list matches for deluxe/webhook = $ROUTE_HIT"

if [ "$ROUTE_HIT" -ge 1 ]; then
  {
    echo "Deluxe guard: $PACKAGE was missing from vendor/composer/installed.json"
    echo "and has been reinstalled on $(hostname) at $STAMP."
    echo
    echo "The /deluxe/webhook route is now registered again."
    echo
    echo "This most likely fired because someone ran Invoice Ninja's built-in"
    echo "self-update, which overwrites installed.json with the vanilla release."
    echo
    echo "Log: $LOG"
  } | mail -s "Deluxe guard: repaired $PACKAGE on $(hostname)" "$MAIL_TO"
  log "REPAIRED"
  exit 0
else
  {
    echo "Deluxe guard: installed.json was patched successfully on $(hostname) at $STAMP"
    echo "but /deluxe/webhook is still not registered. Manual intervention needed."
    echo
    echo "Log: $LOG"
  } | mail -s "Deluxe guard: PARTIAL repair on $(hostname)" "$MAIL_TO"
  log "PARTIAL — installed.json patched but route not registered"
  exit 1
fi
