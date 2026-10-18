# Gated Access & Referral Attribution for WooCommerce

A WooCommerce plugin for stores that sell only to approved buyers. Visitors arrive through a sales rep's referral link, sign in with a one-time code sent by email, and only then see the catalog and prices. Every order records, on the server, which rep brought the buyer.

Work in progress. `NOTES.md` lists what is built so far.

## Requirements

- WordPress 7.1, WooCommerce 11.2, PHP 8.1 or newer. Those are the tested versions; older ones may work but have not been tried.

## Data model

The plugin keeps its data in four tables of its own instead of postmeta or options. They are created with `dbDelta()` on activation. The schema version is stored in the `gaa_db_version` option, and the installer runs again on `init` whenever that stored version is older than the code's. All datetimes are UTC.

| Table | Purpose | Columns |
| --- | --- | --- |
| `{prefix}gaa_identities` | One row per verified buyer email | `id`, `email` (unique, max 190 chars), `verified_at`, `rep_code`, `created_at` |
| `{prefix}gaa_otp_requests` | One row per sign-in code sent; also the source for rate limits | `id`, `email_hash`, `ip_hash`, `code_hash`, `attempts`, `created_at`, `expires_at`, `consumed_at` |
| `{prefix}gaa_processed_events` | Webhook event IDs already applied, for idempotency | `id`, `event_id` (unique), `event_type`, `processed_at` |
| `{prefix}gaa_audit_log` | Who changed what on an identity, and when | `id`, `identity_id`, `event`, `actor`, `context` (JSON), `created_at` |

`gaa_otp_requests` stores no plain email address or IP, only HMAC-SHA256 digests keyed with a WordPress salt. Someone who holds both the database and `wp-config.php` can still test a guessed address against a digest; someone with only a database dump cannot.

Deleting the plugin from the Plugins screen runs `uninstall.php`, which drops the four tables and deletes the plugin's options. Attribution already written to orders stays, because it is part of the store's sales records.

## Development

You need Docker, Node 22, PHP 8.1+ and Composer.

```sh
npm ci
composer install
npx wp-env start      # WordPress on http://localhost:8888, admin / password
composer test         # PHPUnit inside the wp-env tests container
composer lint         # PHPCS with WordPress Coding Standards
```

`.wp-env.json` pins WordPress 7.1.3, WooCommerce 11.2.1 and PHP 8.3. The plugin is mounted as `wp-content/plugins/gated-access-attribution`.
