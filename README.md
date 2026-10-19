# Gated Access & Referral Attribution for WooCommerce

A WooCommerce plugin for stores that sell only to approved buyers. Visitors arrive through a sales rep's referral link, sign in with a one-time code sent by email, and only then see the catalog and prices. Every order records, on the server, which rep brought the buyer.

`NOTES.md` is the handover document: what was verified, what was not, and open questions.

## Requirements

WordPress 7.1, WooCommerce 11.2 and PHP 8.1 or newer. Those are the tested versions; older ones may work but have not been tried. The plugin header declares `Requires Plugins: woocommerce`, so WordPress will not activate it without WooCommerce.

## Installation

The plugin has no build step and no runtime Composer dependencies. Either copy the repository into `wp-content/plugins/gated-access-attribution`, or build an installable zip that leaves out tests and tooling:

```sh
git archive --format=zip --prefix=gated-access-attribution/ -o gated-access-attribution.zip HEAD
```

and upload it under Plugins > Add New > Upload Plugin. Activation:

- creates the four tables described under "Data model";
- creates a `Sign in` page at `/sign-in/` with the `[gaa_sign_in]` shortcode;
- generates a random webhook secret;
- schedules a daily cleanup of old sign-in code requests.

Then add sales reps under WooCommerce > Settings > Gated access, and hand each rep their referral link.

## Configuration

WooCommerce > Settings > Gated access has three fields. The webhook secret box is full width so the whole value is visible for copying.

- Sign-in page: the page holding the `[gaa_sign_in]` shortcode. Activation creates one at `/sign-in/`.
- Sales reps: one rep per line, the code, a colon, then the name, for example `anna: Anna Kowalska`. Codes may use `a-z`, `0-9`, hyphen and underscore, up to 32 characters; they are lower-cased on save, and lines that do not fit are reported and dropped, while the valid ones are saved and listed in a second notice. A rep's referral link is any page of the store with `?ref=<code>` added, for example `https://shop.example/?ref=anna`.
- Webhook secret: the shared secret for the webhook below. Activation generates a random 64-character value. Anything shorter than 32 characters is refused on save, and the old value is kept. The option is not autoloaded.

## Signing in

Activation creates a page at `/sign-in/` containing the `[gaa_sign_in]` shortcode, and stores its ID in the `gaa_sign_in_page_id` option. The shortcode can live on any page, but the plugin links to that one.

1. The visitor enters an email address. The plugin emails a six-digit code.
2. The visitor enters the code. On success the plugin creates the identity (first time) or refreshes `verified_at`, fires `gaa_identity_verified`, sets the session cookie and redirects to the page the visitor came from (or the shop page).

A signed-in buyer who opens the sign-in page sees "Signed in as <address>", a link back to the shop and a "Sign out" button. Signing out is a POST with the form nonce; it deletes the `gaa_session` cookie the way the session set it and returns to the sign-in page, where the catalog gate applies again.

To show the state elsewhere, put `[gaa_session]` in a header, widget or menu. A visitor sees a "Sign in" link to the sign-in page. A signed-in buyer sees their address and a "Sign out" button. The output is personal, so it marks the response uncacheable like the sign-in form does.

Anyone who can read mail at an address can sign in. Restricting who counts as an approved buyer is an open question in `NOTES.md`.

## Checkout email

While a verified session exists and the WooCommerce customer's billing email is empty, both checkouts offer the verified address as the billing email, so the buyer is not shown as a guest with an empty field. The plugin filters `woocommerce_customer_get_billing_email` for the customer the visitor is checking out as. The block checkout gets it in the Store API cart response and the shortcode checkout through `WC_Checkout::get_value()`. Nothing is written into the customer session, and an address the buyer typed is never replaced.

## Catalog gating

A visitor has catalog access if they have a verified session or the `manage_woocommerce` capability (shop managers and administrators). The `gaa_catalog_access` filter can widen or narrow that.

Without access:

| Entry point | What happens |
| --- | --- |
| Shop, product, product category/tag/brand, cart and checkout pages | 302 to the sign-in page, with `redirect_to` set to the requested URL |
| Store API (`/wc/store/...`, every version, including batch) | 401 `gaa_sign_in_required` |
| WooCommerce REST products (`/wc/v1/products`, `/wc/v2/products`, `/wc/v3/products` and their sub-routes) | 401, or 403 for a logged-in user without access |
| WordPress REST `/wp/v2/product`, `product_cat`, `product_tag`, `product_brand` | 401 or 403 |
| Any URL whose main query asks for products through an array, such as `/?post_type[]=product` or its feed | 302 to the sign-in page |
| `?wc-ajax=` endpoints (for example `get_variation`, `checkout`) | 401 JSON, before WooCommerce handles the request |
| `admin-ajax.php` actions starting with `woocommerce_` (WooCommerce registers its storefront events there too) | 401 JSON, at `admin_init` |
| `?add-to-cart=` links and add-to-cart buttons | refused with a notice |
| Classic checkout submission | refused with a validation error |

REST routes are matched on the pattern they were registered with (through `rest_dispatch_request`), not on the request path, so upper-case paths or the unversioned `/wc/store/` aliases do not get around it.

Products are also kept out of places outside the gated pages: front-end and REST `WP_Query` calls (site search, `/wp/v2/search`, query loop blocks), WooCommerce product shortcodes, WooCommerce blocks other than the account link and store notices, oEmbed responses for products, and the XML sitemap (products and product taxonomies are left out for everyone). WooCommerce blocks are skipped in `pre_render_block`, before they run: some, such as the single-product block, load the product straight from the Store API controller and put it into the page's interactivity state, which hiding their HTML afterwards would not remove.

Any response whose content depends on who is asking is marked uncacheable: catalog pages, the sign-in page, and pages where a product query, WooCommerce product shortcode or WooCommerce block ran. That means `nocache_headers()` while headers can still be sent, the `DONOTCACHEPAGE` constant that page caching plugins check, and the `gaa_response_not_cacheable` action for hosts whose cache needs its own signal.

## Referral attribution

1. A visitor opens a link with `?ref=anna`. If `anna` is a configured rep, the code goes into the `gaa_ref` cookie for 30 days. Unknown codes are ignored. Until the visitor signs in, the most recent rep link wins.
2. When the visitor verifies their email, the rep from the cookie is written to `gaa_identities.rep_code`, but only if the identity has no rep yet. The first rep wins; changing it later is an explicit act from the admin screen or the webhook. The cookie is then deleted.
3. At checkout the plugin reads the signed-in identity from the session cookie, loads it from the database, and writes `_gaa_identity_id` and `_gaa_rep_code` to the order with `WC_Order::update_meta_data()` and `save()`. The `gaa_ref` cookie plays no part at this point, so editing it cannot move an order to another rep.

Both checkouts are covered: the classic shortcode checkout through `woocommerce_checkout_order_processed` and the block checkout through `woocommerce_store_api_checkout_order_processed`. If the block checkout processes the same draft order twice (a failed payment, then a retry), the first attribution is kept. The rep's name and code show on the order edit screen, with HPOS on or off.

## Webhook

`POST /wp-json/gated-access/v1/events` lets another system, such as a CRM, move a buyer to another rep.

```json
{
  "event_id": "crm-1842",
  "type": "identity.rep_reassigned",
  "data": { "email": "buyer@example.com", "rep_code": "ben" }
}
```

The `X-GAA-Signature` header must be `sha256=` followed by the hex HMAC-SHA256 of the exact request body, keyed with the webhook secret:

```sh
body='{"event_id":"crm-1842","type":"identity.rep_reassigned","data":{"email":"buyer@example.com","rep_code":"ben"}}'
signature=$(printf '%s' "$body" | openssl dgst -sha256 -hmac "$GAA_WEBHOOK_SECRET" | sed 's/^.* //')
curl -X POST https://shop.example/wp-json/gated-access/v1/events \
  -H 'Content-Type: application/json' \
  -H "X-GAA-Signature: sha256=$signature" \
  --data "$body"
```

| Response | Meaning |
| --- | --- |
| 200 `processed` | The buyer now has the new rep; a `rep.reassigned` audit entry records the change with actor `webhook:<event_id>` |
| 200 `unchanged` | The buyer already had that rep |
| 200 `duplicate` | This `event_id` was processed before; nothing changed |
| 400 | The body is not JSON, or does not match the schema (`event_id` is 1 to 128 of `A-Z a-z 0-9 . _ : -`) |
| 401 | Missing or wrong signature, or no secret of at least 32 characters is configured |
| 404 | No identity has that email address |
| 422 | The rep code is not configured |

Each `event_id` is applied at most once, compared byte for byte (`evt_A` and `evt_a` are different events). It is claimed in `gaa_processed_events` (unique key) before anything changes, so two identical deliveries arriving together cannot both apply. A 404 or 422 releases the claim, so the sender can deliver the same event again once the buyer or rep exists. Orders placed before a reassignment keep the rep they were attributed to.

## Admin screen

WooCommerce > Buyer identities, for users with `manage_woocommerce`:

- Without a search, the 20 most recently verified buyers.
- Look up a buyer by email address (exact match, case-insensitive) to see their rep, when they first and last verified, their 20 most recent attributed orders, and their history: sign-ins, rep assignments and changes, attributed orders, each with who caused it.
- Move the buyer to another rep. The change is recorded in the audit log with the user who made it. Orders already placed keep their rep.

## Hooks

| Hook | Type | Use |
| --- | --- | --- |
| `gaa_identity_verified` | action | Runs after a buyer enters a correct code. Receives the `GatedAccess\Identity`. |
| `gaa_catalog_access` | filter | Whether the current visitor may see the catalog. Receives the plugin's decision (verified session or `manage_woocommerce`). |
| `gaa_client_ip` | filter | The IP address used for per-IP rate limits. Defaults to `REMOTE_ADDR`. |
| `gaa_send_cookies` | filter | Whether the plugin sends its cookies. Receives the cookie name. Tests turn it off. |
| `gaa_response_not_cacheable` | action | Runs when the plugin marks the current response as personal. For hosts whose page cache needs a signal other than `DONOTCACHEPAGE`. |

## Security model

### Sign-in codes

- Codes are six digits from `random_int()`. Each is valid for 10 minutes and works once.
- The database never holds a code, only an HMAC-SHA256 of it keyed with `wp_salt( 'auth' )` and bound to a digest of the address it was sent to. Codes are compared with `hash_equals()`.
- Each code allows 5 attempts. The attempt counter is raised by a conditional `UPDATE` before the comparison, so parallel guesses cannot get past the limit.
- Requesting a new code retires every earlier code for the address, so there is only ever one live code per address.
- Rate limits: 3 codes per address per 15 minutes and 10 per address per 24 hours, 10 codes per IP per hour, all counted from `gaa_otp_requests`. The daily cap bounds guessing at 50 tries a day per address, however many IPs ask. The IP is `REMOTE_ADDR`. Behind a reverse proxy you control, return the real client address from the `gaa_client_ip` filter; forwarded headers are not trusted by default.
- No account enumeration. Requesting a code runs the same queries and sends the same email whether or not the address belongs to an identity, and identities are only created on the first successful verification. Every verification failure (no code, wrong, expired, used, out of attempts) gives the same message.
- A daily cron event deletes `gaa_otp_requests` rows older than 48 hours.
- Failed `wp_mail()` calls are written to the WooCommerce log (source `gated-access-attribution`) without the recipient address.

### Webhook

- `permission_callback` recomputes `HMAC-SHA256(raw body, secret)` and compares it with the `X-GAA-Signature` header through `hash_equals()`. With no secret, or one shorter than 32 characters, every request is refused.
- Refused requests are logged to the WooCommerce log (source `gated-access-attribution`) without the body or the secret.
- The handler acts only on the parsed JSON body, the part the signature covers. WordPress would otherwise take missing or all parameters from the query string, so a captured signed body could be resent with another `event_id`, email and rep in its URL; such a request now gets 400.
- There is no timestamp in the signature. A captured request can be replayed, but a replay carries an `event_id` already processed, so it changes nothing.

### Form and session

- Each form step checks a WordPress nonce and rejects POSTs whose `Origin` header names another host. The nonce alone is weak for logged-out visitors, because WordPress gives them all the same one.
- `redirect_to` goes through `wp_validate_redirect()`, so a link can only send the buyer to a page on this site.
- The session cookie `gaa_session` holds `identity id|expiry|HMAC`. It is `HttpOnly`, `SameSite=Lax`, `Secure` on HTTPS, and lasts 14 days. The identity is re-read from the database on each request, so deleting an identity ends its sessions.

### Admin screen

- The menu entry, the screen and the reassignment handler each check `manage_woocommerce`.
- Reassignment is a POST to `admin-post.php` with a nonce bound to the identity's ID (`gaa_reassign_rep_<id>`), checked with `check_admin_referer()`. A nonce issued for one identity does not work for another.
- Every value printed is escaped where it is printed, including values read back from the audit log. The notice after a reassignment is chosen from three fixed messages by key; nothing from the URL is echoed.

## Data model

The plugin keeps its data in four tables of its own instead of postmeta or options. They are created with `dbDelta()` on activation. The schema version is stored in the `gaa_db_version` option, and the installer runs again on `init` whenever that stored version is older than the code's. All datetimes are UTC.

| Table | Purpose | Columns |
| --- | --- | --- |
| `{prefix}gaa_identities` | One row per verified buyer email | `id`, `email` (unique, max 190 chars), `verified_at`, `rep_code`, `created_at` |
| `{prefix}gaa_otp_requests` | One row per sign-in code sent; also the source for rate limits | `id`, `email_hash`, `ip_hash`, `code_hash`, `attempts`, `created_at`, `expires_at`, `consumed_at` |
| `{prefix}gaa_processed_events` | Webhook event IDs already applied, for idempotency | `id`, `event_id` (unique, ASCII, binary collation), `event_type`, `processed_at` |
| `{prefix}gaa_audit_log` | Who changed what on an identity, and when | `id`, `identity_id`, `event`, `actor`, `context` (JSON), `created_at` |

`gaa_otp_requests` stores no plain email address or IP, only HMAC-SHA256 digests keyed with a WordPress salt. Someone who holds both the database and `wp-config.php` can still test a guessed address against a digest; someone with only a database dump cannot.

`gaa_audit_log` rows are only ever inserted. The events are `identity.verified` (actor `buyer`), `rep.assigned` (actor `referral-link`), `rep.reassigned` (actor `user:<id>` or `webhook:<event_id>`, context `from` and `to`) and `order.attributed` (actor `checkout`, context `order_id` and `rep_code`). The admin screen lists a buyer's orders from the `order.attributed` entries, because `wc_get_orders()` only accepts `meta_query` under HPOS.

Orders carry two meta keys, written through the WooCommerce CRUD API so they land in `wc_orders_meta` under HPOS and in `postmeta` otherwise: `_gaa_identity_id` and `_gaa_rep_code`.

Deleting the plugin from the Plugins screen runs `uninstall.php`, which drops the four tables, deletes the plugin's options and deletes the sign-in page it created. Pages are recognised by the `_gaa_created_by_plugin` meta key, so a page an admin chose as the sign-in page stays. Attribution already written to orders stays, because it is part of the store's sales records.

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

### Tests

The suite in `tests/integration/` runs on the WordPress test library with WooCommerce loaded, and covers the OTP rules and rate limits, the session cookie, the sign-in form, attribution through real classic and Store API checkouts, the gate on pages, Store API, REST, wc-ajax, cart and checkout, product visibility outside the gate, the webhook signature and idempotency, the admin screen, and install, upgrade and uninstall. GitHub Actions runs `composer lint`, then `composer test` inside wp-env on PHP 8.1 and 8.3.

### Seeing the sign-in emails

```sh
npm run mail:start    # Mailpit, web UI on http://localhost:8025
npm run mail:stop
```

`dev/mailpit.php` is mounted into the wp-env site as a must-use plugin. It sends all mail over SMTP to `host.docker.internal:1025` and sets the sender to `store@example.test`, because WordPress's default sender on a localhost site (`wordpress@localhost`) fails its own address check. It is not part of the plugin.
