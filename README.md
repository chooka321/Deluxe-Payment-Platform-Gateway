# Deluxe Payment Platform for Invoice Ninja

A payment gateway plugin for [Invoice Ninja](https://invoiceninja.com) v5 (tested on 5.13.x) that takes credit card and ACH payments through the [Deluxe Payment Platform](https://developer.deluxe.com).

The plugin uses Deluxe's **Payment Link** (hosted page) flow:

1. A client clicks **Pay** in the Invoice Ninja client portal.
2. The driver calls `POST /dpp/v1/paymentlinks` and sends the client to the Deluxe-hosted payment page.
3. The client pays on Deluxe's page.
4. Deluxe sends a `TRANSACTION` webhook to `/deluxe/webhook`, and the plugin records the payment in Invoice Ninja.

Card payments are recorded as completed. ACH payments are recorded as pending until Deluxe reports `APPROVED`/`SETTLED`, and an `ACH REJECT` webhook marks them failed.

## Features

- Credit card and ACH (bank transfer)
- Separate sandbox and production credentials, switched by a **Production Mode** setting
- OAuth2 client-credentials auth, with the bearer token cached for 50 minutes
- Refunds through the Deluxe `refunds` API
- Webhook route registered by the package's service provider, so it stays in place across Invoice Ninja updates
- Optional admin UI tweak that shows the Production Mode toggle as a checkbox

## Installation

1. Copy this repository into your Invoice Ninja install as `packages/Deluxe`.

2. Add the path repository and the requirement to Invoice Ninja's root `composer.json`:

   ```json
   "repositories": [
       { "type": "path", "url": "packages/Deluxe" }
   ],
   "require": {
       "chooka321/deluxe-payment-platform": "*"
   }
   ```

   Then run `composer update chooka321/deluxe-payment-platform`.

3. Register the gateway in the database:

   ```bash
   php artisan vendor:publish --tag=deluxe-migrations
   php artisan migrate
   ```

4. Move the gateway to ID 1000, well clear of Invoice Ninja's own numbers (its seeder assigns new gateways the next ID in sequence):

   ```sql
   UPDATE gateways SET id = 1000 WHERE `key` = 'deluxepaymentplatform';
   ```

   This is safe because `company_gateways` references gateways by `gateway_key`, not by ID. Then add the gateway's supported methods to `app/Models/Gateway.php` → `getMethods()`:

   ```php
   case 1000:
       return [
           GatewayType::CREDIT_CARD   => ['refund' => true,  'token_billing' => false, 'webhooks' => ['TRANSACTION']],
           GatewayType::BANK_TRANSFER => ['refund' => false, 'token_billing' => false, 'webhooks' => ['TRANSACTION', 'ACH REJECT']],
       ]; // Deluxe Payment Platform
   ```

   > **Why not the migration's auto-increment ID?** Invoice Ninja assigns its own gateways sequential IDs and hard-codes them in `getMethods()`. For example, ID 67 later became Payware. If Deluxe shares an ID with one of them, it takes on that gateway's payment methods.

5. Clear caches with `php artisan optimize:clear`. If you use OPcache, reload PHP-FPM too.

6. In Invoice Ninja, go to **Settings → Payment Settings → Add Gateway → Deluxe Payment Platform** and enter your credentials.

7. In the Deluxe merchant portal, subscribe your webhook to `https://<your-invoice-ninja-host>/deluxe/webhook`.

## Configuration fields

| Field | Purpose |
|---|---|
| `productionMode` | Off: uses the sandbox credentials and `sandbox.api.deluxe.com`. On: uses the production credentials and `api.deluxe.com`. |
| `sandbox/productionAccessToken` | Merchant partner token, sent as the `partnerToken` header |
| `sandbox/productionClientId` | OAuth2 client ID |
| `sandbox/productionClientSecret` | OAuth2 client secret |
| `sandbox/productionSecretKey` | Not used by Payment Link. Reserved for Embedded Payments. |

Credentials are stored encrypted by Invoice Ninja in the `company_gateways` table. None are stored in this repository.

## Surviving Invoice Ninja self-updates

Invoice Ninja's built-in updater extracts the release tarball over the app tree. That replaces `composer.json`, `app/Models/Gateway.php` and `vendor/composer/installed.json`, which removes the plugin's wiring. The package directory itself is left alone. The `scripts/` folder has cron-friendly helpers that restore the wiring:

| Script | What it does |
|---|---|
| `deluxe-provider-guard.sh` | Repairs the package registration offline: rebuilds the `installed.json` entry, recreates the vendor symlink, runs `dump-autoload` and `package:discover`, clears caches and emails the result. It doesn't need network access or GitHub auth. |
| `monitor.sh` | Adds the `composer.json` path repo/require and the `Gateway.php` `case` block back if they're missing. |
| `testmode-watch.sh` | Clears the Laravel cache when the Deluxe gateway settings are saved. |

### Settings

The scripts aren't edited directly. They read their settings from environment variables, and fall back to the default if a variable isn't set:

| Variable | Used by | Default | What to set it to |
|---|---|---|---|
| `IN_APP_DIR` | all three | `/var/www/invoiceninja` | The Invoice Ninja root folder, the one containing `artisan` and `.env` |
| `IN_USER` | all three | `www-data` | The Linux user PHP-FPM runs Invoice Ninja as (see below) |
| `DELUXE_GUARD_MAIL_TO` | `deluxe-provider-guard.sh` | `root@localhost` | Where repair and failure alerts are emailed |
| `DELUXE_GUARD_LOG` | `deluxe-provider-guard.sh` | `/var/log/deluxe-guard.log` | Log file path |

If you're not sure which user Invoice Ninja runs as, check who owns its files:

```bash
stat -c '%U' /var/www/invoiceninja/artisan
```

### Where to set them: a cron file (recommended)

**What cron is:** cron is the Linux service that runs commands on a schedule. Every file in the `/etc/cron.d/` folder on your server is a list of scheduled jobs, and cron rereads that folder every minute.

**What this file does:** it tells cron to run the three repair scripts automatically, every few minutes, forever. After an Invoice Ninja update knocks out the plugin's wiring, the scripts put it back within 15 minutes, with no one logged in. The settings at the top of the file are the environment variables described above, and they're passed to every script the file runs.

**Where it goes:** on the server that runs Invoice Ninja, as a new file at `/etc/cron.d/deluxe-invoiceninja`. It's a new file you create, not one that already exists. It's separate from your user crontab (`crontab -e`), and Invoice Ninja updates don't touch it.

Create the file as root:

```bash
sudo nano /etc/cron.d/deluxe-invoiceninja
```

Paste the following, then change the four settings at the top and the `SCRIPTS` path to match your server:

```
# --- Settings: edit these -------------------------------------------
IN_APP_DIR=/var/www/invoiceninja
IN_USER=www-data
DELUXE_GUARD_MAIL_TO=you@example.com
DELUXE_GUARD_LOG=/var/log/deluxe-guard.log
SCRIPTS=/var/www/invoiceninja/packages/Deluxe/scripts

# --- Schedule ---------------------------------------------------------
*/15 * * * * root /bin/bash $SCRIPTS/deluxe-provider-guard.sh
*/10 * * * * root /bin/bash $SCRIPTS/monitor.sh
* * * * *    root /bin/bash $SCRIPTS/testmode-watch.sh
```

What each schedule line means. The five fields before `root` are minute, hour, day of month, month and day of week, and `*` means "every":

| Line | Runs | Why |
|---|---|---|
| `*/15 * * * * root … deluxe-provider-guard.sh` | Every 15 minutes | Checks that Invoice Ninja still has the plugin registered. If an update dropped it, the guard re-registers it, restoring the `/deluxe/webhook` route, and emails you. If nothing is wrong, it exits without doing anything. |
| `*/10 * * * * root … monitor.sh` | Every 10 minutes | Checks that `composer.json` and `app/Models/Gateway.php` still have the Deluxe lines, and adds them back if an update removed them. |
| `* * * * * root … testmode-watch.sh` | Every minute | Notices when someone saves the Deluxe gateway settings, such as the Production Mode switch, and clears Invoice Ninja's cache so the change takes effect right away. |

`root` is the user each job runs as.

Cron picks up the new file on its own within a minute, so you don't need to restart anything. To confirm the jobs are running, watch the system log for them:

```bash
grep deluxe /var/log/syslog        # Debian/Ubuntu
journalctl -u cron | grep deluxe   # systemd-based systems
```

Variables set at the top of a cron file apply to every job below them. Cron's syntax is stricter than a normal shell:

- Write `NAME=value` with no spaces around `=` and no `export`.
- Use full paths. Cron won't expand `~` or `$HOME`, or a `$VAR` inside the value of another setting. Only the commands on the schedule lines can use `$VAR`.
- The file name must not contain a dot (`deluxe-invoiceninja`, not `deluxe.cron`). Cron silently ignores files that do.
- The file must be owned by root and must not be writable by group or others: `sudo chown root:root /etc/cron.d/deluxe-invoiceninja && sudo chmod 644 /etc/cron.d/deluxe-invoiceninja`.
- The file must end with a newline.

The jobs run as `root` because the scripts switch to `IN_USER` themselves, using `sudo -u`, and need to fix file ownership. Email alerts need a working `mail` command (from `mailutils` or `bsd-mailx`).

### Running a script by hand

To test your settings, or to run a repair right away, put the variables before the command:

```bash
sudo IN_APP_DIR=/var/www/invoiceninja IN_USER=www-data DELUXE_GUARD_MAIL_TO=you@example.com \
  bash /var/www/invoiceninja/packages/Deluxe/scripts/deluxe-provider-guard.sh
```

The variables have to come after `sudo`. If you `export` them in your own shell first, `sudo` drops them, and the scripts quietly fall back to the defaults.

When everything is in order, the guard exits silently. If it repaired something, it says so in `DELUXE_GUARD_LOG` and emails you. `monitor.sh` and `testmode-watch.sh` write to `scripts/monitor.log` inside the package folder.

### Gateway ID

`monitor.sh` expects the gateway at ID `1000` (see step 4).

## Deluxe API gotchas

These are the payload details we had to work out by trial and error:

- `amount.amount` must be a JSON number, not a string.
- `paymentLinkExpiry` must match `^([0-9]{1,3} (DAY|WEEK|MONTH|YEAR)S?|NO EXPIRATION)$`, for example `"1 DAYS"`.
- `deliveryMethod.email` and `.sms` must be strings, even with `type: "None"`. Empty strings work.
- `firstName`/`lastName` are top-level fields. `orderData.orderId` is required.
- Webhook payloads use PascalCase keys (`EventType`, `CustomFields`, …). The handler lowercases every key before reading them.
- Embedded Payments (the in-page JS SDK) needs a separate *Signature Key* from Deluxe's integrations team. Your client secret or access token won't work for it. The `pay.blade.php` views and `generateJwt()` are left over from that approach and aren't used by the Payment Link flow.

## Notes

- `CreditCard`/`ACH` `paymentResponse()` are legacy post-back handlers. The Payment Link flow records payments only through the webhook.

## Author

chooka321 <347108626+chooka321@users.noreply.github.com>
