# Gated Access & Referral Attribution for WooCommerce

A WooCommerce plugin for stores that sell only to approved buyers. Visitors arrive through a sales rep's referral link, sign in with a one-time code sent by email, and only then see the catalog and prices. Every order records, on the server, which rep brought the buyer.

Work in progress. `NOTES.md` lists what is built so far.

## Requirements

- WordPress 7.1, WooCommerce 11.2, PHP 8.1 or newer. Those are the tested versions; older ones may work but have not been tried.

## Configuration

WooCommerce > Settings > Gated access has two fields.

- Sign-in page: the page holding the `[gaa_sign_in]` shortcode. Activation creates one at `/sign-in/`.
- Sales reps: one rep per line, the code, a colon, then the name, for example `anna: Anna Kowalska`. Codes may use `a-z`, `0-9`, hyphen and underscore, up to 32 characters; they are lower-cased on save, and lines that do not fit are reported and dropped. A rep's referral link is any page of the store with `?ref=<code>` added, for example `https://shop.example/?ref=anna`.

## Signing in

Activation creates a page at `/sign-in/` containing the `[gaa_sign_in]` shortcode, and stores its ID in the `gaa_sign_in_page_id` option. The shortcode can live on any page, but the plugin links to that one.

1. The visitor enters an email address. The plugin emails a six-digit code.
2. The visitor enters the code. On success the plugin creates the identity (first time) or refreshes `verified_at`, fires `gaa_identity_verified`, sets the session cookie and redirects to the page the visitor came from (or the shop page).

Anyone who can read mail at an address can sign in. Restricting who counts as an approved buyer is an open question in `NOTES.md`.

## Catalog gating

A visitor has catalog access if they have a verified session or the `manage_woocommerce` capability (shop managers and administrators). The `gaa_catalog_access` filter can widen or narrow that.

Without access:

| Entry point | What happens |
| --- | --- |
| Shop, product, product category/tag/brand, cart and checkout pages | 302 to the sign-in page, with `redirect_to` set to the requested URL |
| Store API (`/wc/store/...`, every version, including batch) | 401 `gaa_sign_in_required` |
| WooCommerce REST products (`/wc/v1/products`, `/wc/v2/products`, `/wc/v3/products` and their sub-routes) | 401, or 403 for a logged-in user without access |
| WordPress REST `/wp/v2/product`, `product_cat`, `product_tag`, `product_brand` | 401 or 403 |
| `?wc-ajax=` endpoints (for example `get_variation`, `checkout`) | 401 JSON, before WooCommerce handles the request |
| `?add-to-cart=` links and add-to-cart buttons | refused with a notice |
| Classic checkout submission | refused with a validation error |

REST routes are matched on the pattern they were registered with (through `rest_dispatch_request`), not on the request path, so upper-case paths or the unversioned `/wc/store/` aliases do not get around it.

Products are also kept out of places outside the gated pages: front-end and REST `WP_Query` calls (site search, `/wp/v2/search`, query loop blocks), WooCommerce product shortcodes, WooCommerce blocks other than the account link and store notices, oEmbed responses for products, and the XML sitemap (products and product taxonomies are left out for everyone).

Catalog pages are sent with `nocache_headers()` and `DONOTCACHEPAGE` for every visitor, because their content depends on who is asking.

## Referral attribution

1. A visitor opens a link with `?ref=anna`. If `anna` is a configured rep, the code goes into the `gaa_ref` cookie for 30 days. Unknown codes are ignored. Until the visitor signs in, the most recent rep link wins.
2. When the visitor verifies their email, the rep from the cookie is written to `gaa_identities.rep_code`, but only if the identity has no rep yet. The first rep wins; changing it later is an explicit act from the admin screen or the webhook. The cookie is then deleted.
3. At checkout the plugin reads the signed-in identity from the session cookie, loads it from the database, and writes `_gaa_identity_id` and `_gaa_rep_code` to the order with `WC_Order::update_meta_data()` and `save()`. The `gaa_ref` cookie plays no part at this point, so editing it cannot move an order to another rep.

Both checkouts are covered: the classic shortcode checkout through `woocommerce_checkout_order_processed` and the block checkout through `woocommerce_store_api_checkout_order_processed`. If the block checkout processes the same draft order twice (a failed payment, then a retry), the first attribution is kept. The rep's name and code show on the order edit screen, with HPOS on or off.

## Security model

### Sign-in codes

- Codes are six digits from `random_int()`. Each is valid for 10 minutes and works once.
- The database never holds a code, only an HMAC-SHA256 of it keyed with `wp_salt( 'auth' )` and bound to a digest of the address it was sent to. Codes are compared with `hash_equals()`.
- Each code allows 5 attempts. The attempt counter is raised by a conditional `UPDATE` before the comparison, so parallel guesses cannot get past the limit.
- Requesting a new code retires every earlier code for the address, so there is only ever one live code per address.
- Rate limits: 3 codes per address per 15 minutes, 10 codes per IP per hour, both counted from `gaa_otp_requests`. The IP is `REMOTE_ADDR`. Behind a reverse proxy you control, return the real client address from the `gaa_client_ip` filter; forwarded headers are not trusted by default.
- No account enumeration. Requesting a code runs the same queries and sends the same email whether or not the address belongs to an identity, and identities are only created on the first successful verification. Every verification failure (no code, wrong, expired, used, out of attempts) gives the same message.
- A daily cron event deletes `gaa_otp_requests` rows older than 24 hours.
- Failed `wp_mail()` calls are written to the WooCommerce log (source `gated-access-attribution`) without the recipient address.

### Form and session

- Each form step checks a WordPress nonce and rejects POSTs whose `Origin` header names another host. The nonce alone is weak for logged-out visitors, because WordPress gives them all the same one.
- `redirect_to` goes through `wp_validate_redirect()`, so a link can only send the buyer to a page on this site.
- The session cookie `gaa_session` holds `identity id|expiry|HMAC`. It is `HttpOnly`, `SameSite=Lax`, `Secure` on HTTPS, and lasts 14 days. The identity is re-read from the database on each request, so deleting an identity ends its sessions.

## Data model

The plugin keeps its data in four tables of its own instead of postmeta or options. They are created with `dbDelta()` on activation. The schema version is stored in the `gaa_db_version` option, and the installer runs again on `init` whenever that stored version is older than the code's. All datetimes are UTC.

| Table | Purpose | Columns |
| --- | --- | --- |
| `{prefix}gaa_identities` | One row per verified buyer email | `id`, `email` (unique, max 190 chars), `verified_at`, `rep_code`, `created_at` |
| `{prefix}gaa_otp_requests` | One row per sign-in code sent; also the source for rate limits | `id`, `email_hash`, `ip_hash`, `code_hash`, `attempts`, `created_at`, `expires_at`, `consumed_at` |
| `{prefix}gaa_processed_events` | Webhook event IDs already applied, for idempotency | `id`, `event_id` (unique), `event_type`, `processed_at` |
| `{prefix}gaa_audit_log` | Who changed what on an identity, and when | `id`, `identity_id`, `event`, `actor`, `context` (JSON), `created_at` |

`gaa_otp_requests` stores no plain email address or IP, only HMAC-SHA256 digests keyed with a WordPress salt. Someone who holds both the database and `wp-config.php` can still test a guessed address against a digest; someone with only a database dump cannot.

Orders carry two meta keys, written through the WooCommerce CRUD API so they land in `wc_orders_meta` under HPOS and in `postmeta` otherwise: `_gaa_identity_id` and `_gaa_rep_code`.

Deleting the plugin from the Plugins screen runs `uninstall.php`, which drops the four tables and deletes the plugin's options. Attribution already written to orders stays, because it is part of the store's sales records.

## Development

You need Docker, Node 22, PHP 8.1+ and Composer.

```sh
npm ci
composer install
npx wp-env start      # WordPress on http://localhost:8888, admin / password
composer test         # PHPUnit inside wp-env, once with HPOS and once with posts storage
composer lint         # PHPCS with WordPress Coding Standards
```

`composer test:hpos` and `composer test:posts` run a single pass. Order storage is chosen once per PHPUnit process through the `GAA_TEST_ORDER_STORAGE` environment variable, because WooCommerce does not switch order data stores cleanly in the middle of a process.

`.wp-env.json` pins WordPress 7.1.3, WooCommerce 11.2.1 and PHP 8.3. The plugin is mounted as `wp-content/plugins/gated-access-attribution`.

### Seeing the sign-in emails

```sh
npm run mail:start    # Mailpit, web UI on http://localhost:8025
npm run mail:stop
```

`dev/mailpit.php` is mounted into the wp-env site as a must-use plugin. It sends all mail over SMTP to `host.docker.internal:1025` and sets the sender to `store@example.test`, because WordPress's default sender on a localhost site (`wordpress@localhost`) fails its own address check. It is not part of the plugin.
