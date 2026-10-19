# Engineering notes

Last updated 2026-10-19.

What is built, how the tests run, what was checked and what was not, the design decisions that are not obvious from the code, and the questions the plugin leaves to the store owner. `README.md` describes behaviour; this file describes the reasoning around it.

## Status

All planned parts are built:

| Part | Main code |
| --- | --- |
| Scaffold, wp-env, test harness, CI | `gated-access-attribution.php`, `includes/autoload.php`, `tests/bootstrap.php`, `.github/workflows/ci.yml` |
| Own tables | `Schema`, `uninstall.php` |
| Passwordless sign-in | `Otp_Service`, `Session`, `Sign_In_Form`, `Request_Guard`, `Sign_In_Page` |
| Server-side attribution | `Referral`, `Order_Attribution`, `Rep_Codes`, `Settings` |
| Catalog gating | `Catalog_Access`, `Catalog_Gate`, `Catalog_Visibility` |
| Webhook | `Webhook_Controller`, `Processed_Events` |
| Audit log and admin screen | `Audit_Log`, `Admin\Identity_Screen` |

At the last commit: 177 integration tests, 390 assertions, passing once with HPOS and once with posts storage. `composer lint` (WPCS 3.4.1, PHPCompatibilityWP with an 8.1 floor) is clean.

### Fixed after the first review pass

A review pass looked for ways around the design. It found eight problems, and four were reproduced with scratch tests before the fix. Each fix has a test that fails without it.

| Area | Problem | Fix |
| --- | --- | --- |
| Gate | Logged-out `admin-ajax.php?action=woocommerce_get_variation` returned prices; admin-ajax never fires `template_redirect` | 401 for `woocommerce_` admin-ajax actions at `admin_init` |
| Webhook | A captured signed body resent as `text/plain` took `event_id`, email and rep from the query string | Handler reads only `get_json_params()` |
| Visibility | The single-product block put product name and prices into interactivity state even with its HTML dropped | `pre_render_block` instead of `render_block` |
| Gate | `/?post_type[]=product` (and its feed) listed products on the home page | Gate also checks the main query's post type array |
| Uninstall | Force-deleted whatever page the sign-in setting pointed at | Only pages flagged `_gaa_created_by_plugin` are deleted |
| Caching | Per-visitor output outside catalog pages was not marked uncacheable | `Page_Cache::forbid()` on the sign-in page and wherever products could render |
| OTP | 3 codes per 15 minutes allowed about 1,440 guesses a day per address | Daily cap of 10 codes per address |
| Schema | `event_id` unique key was case-insensitive, so `evt_A` swallowed `evt_a` | ASCII column with binary collation |

### Fixed after the second review pass

| Area | Problem | Fix |
| --- | --- | --- |
| OTP | `is_rate_limited()` read a failed COUNT query as zero requests, so a database error switched the limits off | Any null result from a limit query counts as limited |
| Webhook | `Processed_Events::claim()` returned false for a duplicate and for a failed INSERT, so a database error was answered as a duplicate and the event was lost | `claim()` throws `Storage_Error`; the controller answers 503 so the sender retries |
| Audit log | `webhook:<event_id>` could be 136 characters in a 100-character column, and `record()` ignored a failed insert. WordPress turns off strict SQL mode, so the actor was cut short without an error | Event IDs are capped at 92 characters, an overlong actor is refused, a failed insert throws `Storage_Error`. The webhook answers 503, the admin screen shows an error notice, sign-in shows its "unavailable" message, and checkout logs the failure without failing the order |
| Visibility | `is_admin()` is true for `admin-ajax.php`, including `nopriv` actions, so a theme's AJAX product search skipped the query filter | The filter skips only `is_admin() && ! wp_doing_ajax()`; store staff still pass the access check |

The webhook and the admin screen write the audit entry before they change the rep. If the entry cannot be written, the rep is untouched and a retry starts clean.

### Not done

- No WordPress privacy exporter or eraser. The plugin stores buyer email addresses (`gaa_identities`) and an audit trail per identity. A store in the EU will want both hooked into Tools > Export/Erase Personal Data.
- No `.pot` file. All strings use the `gated-access-attribution` text domain, but none were extracted.
- No multisite support (see below).
- `npm audit --audit-level=high` reports advisories in `@wordpress/env` 11.17.0 (simple-git, qs, sprintf-js through its dependencies), which is the latest release. It is development tooling that never reaches a site, so the CI step reports without failing. `composer audit` is clean.

## How the tests run

### With wp-env (CI and normal development)

GitHub Actions runs `npx wp-env start`, then `composer test`, on PHP 8.1 and 8.3. The suite runs inside the wp-env `tests-cli` container. `npm ci`, `composer audit` and `composer lint` run first.

### Without wp-env

On one build machine wp-env could not build its Docker images: the generated Dockerfiles run `apk update` and `apt-get`, and the container network could not resolve `dl-cdn.alpinelinux.org` or `deb.debian.org` (the image build also needs `getcomposer.org` and `composer.github.io`). The error looked like this:

```
#13 [tests-cli  4/15] RUN apk update
WARNING: updating and opening https://dl-cdn.alpinelinux.org/alpine/v3.24/main/x86_64/APKINDEX.tar.gz: DNS: name does not exist
ERROR: process "/bin/sh -c apk update" did not complete successfully: exit code: 2
```

`npx wp-env start` itself downloads WordPress 7.1.3, WooCommerce 11.2.1 and the WordPress PHPUnit library without trouble; only the image build needs those four hosts. If you hit the same wall, the same PHPUnit suite and the same `tests/bootstrap.php` run on a host fallback, laid out the way WP-CLI's `install-wp-tests.sh` does it:

- PHP 8.3 on the host and a `mariadb:lts` container (12.3.3 was used)
- WordPress 7.1.3 from `https://downloads.wordpress.org/release/wordpress-7.1.3.zip`
- WooCommerce 11.2.1 from `https://downloads.wordpress.org/plugin/woocommerce.11.2.1.zip`
- `tests/phpunit` from `WordPress/wordpress-develop` at tag `7.1.3`
- the plugin symlinked as `wp-content/plugins/gated-access-attribution`
- `WP_TESTS_DIR` pointing at that test library, table prefix `wptests_`

The fallback is not part of the repo. Test counts quoted for a change say which runner produced them.

Smaller problems on that machine: Composer could not download dist zips from `api.github.com` and cloned each package from github.com instead (installs work but take several minutes), and Docker Hub rate-limited the pull of `axllent/mailpit:v1.31.4`, so the Mailpit setup was not run there.

## Not verified

- Browser coverage is the pass described under "Browser pass" and nothing more: Twenty Twenty-Five on wp-env, Chromium only. The admin identities screen and the order edit screen were not part of the fix pass.
- The block checkout is tested in-process through the Store API endpoint the block calls, with the nonce check turned off (`woocommerce_store_api_disable_nonce_check`). The browser pass loaded the block checkout but did not place an order.
- Older versions. WordPress 7.1.3 and WooCommerce 11.2.1 are the only versions tested, so the plugin header claims nothing older.
- MySQL. Every run used MariaDB (12.3.3 on the fallback, wp-env's `mariadb:lts` in CI). The "second install changes nothing" schema test is the one that would catch a dbDelta definition MySQL 8 reports differently.
- Multisite. Activation and uninstall act on the current site only. Network activation is not handled.
- Timing. The code paths for known and unknown addresses are the same, but response times were never measured.
- Concurrency. The OTP attempt counter, rep binding and webhook claims are race-safe by construction (conditional `UPDATE`s and a unique key); the rate limits are not (see below). Nothing was load-tested.
- Database failures. The fail-closed and 503 paths are tested by rewriting the failing statement through the `query` filter. A real lost connection or full disk was not tried.
- Page caching. Personal responses send `nocache_headers()`, define `DONOTCACHEPAGE` and fire `gaa_response_not_cacheable`, but no caching plugin, proxy or host cache was tried. A cache that ignores both the headers and the constant, and does not know the `gaa_session` cookie, would still serve one visitor's page to another.
- Product category, tag and brand names on pages that are not gated. Widgets and blocks that list terms use `get_terms()`, which the query filter does not see. WooCommerce blocks are hidden before sign-in, but a theme or another plugin printing terms its own way would still show the names (not products or prices).
- The mini-cart is hidden before sign-in (it is a WooCommerce block, and its Store API calls would get 401 anyway). A theme that calls the Store API from its own scripts on public pages would see 401s for visitors who have not signed in.

## Browser pass

A walkthrough of the running wp-env site (Twenty Twenty-Five, WooCommerce 11.2.1, Mailpit) found problems the tests could not see. Fixed:

- Sign-in buttons were native, unstyled buttons. They now carry `wp-element-button` and `button`. The two secondary actions after a code was sent, and "Sign out", are link-styled buttons of the same 44px height; a small stylesheet (`assets/sign-in.css`) loads only where a plugin shortcode renders.
- The "code sent" notice used the info style, which shows a generic window icon. It now uses `woocommerce-message`, so success and error notices both match the theme.
- Sign-out and the signed-in view already existed on the sign-in page, but nothing showed the state elsewhere. Added the `[gaa_session]` shortcode.
- Checkout showed a verified buyer as a guest with an empty email. The billing email getter now falls back to the verified address for both checkouts.
- Settings: a save that dropped some rep lines gave no sign that the others were kept (WooCommerce prints its success message only when there are no errors). Also removed the repeated "Gated access" title and widened the webhook secret field, which WooCommerce's stylesheet fixes at 400px.
- `dev/mailpit.php` now sends mail from the site name instead of "WordPress". wp-env mounts that file as a single-file bind mount, so after editing it the containers need a restart to see the change.

Found and not the plugin's doing:

- Two requests to `/cart/undefinedwc/store/v1/cart` (301) while the block cart loads. With the plugin deactivated, `/cart/` as admin with one item still sent `GET /cart/undefinedwc/store/v1/cart` and got the same 301. WooCommerce or the theme builds a Store API URL from an undefined variable. Left alone. On the pass with the plugin active only one such request showed up, not two.
- "You are currently checking out as a guest." stays under the prefilled email. It is the block checkout's text for any visitor who is not a WordPress user, and a verified buyer is not one by design (see "Sign-in and session").

## Design decisions

### Sign-in and session

- Identities are created on the first successful verification, never on request. That keeps unverified addresses out of `gaa_identities` and is what makes the "known address" question unanswerable from outside.
- `gaa_otp_requests` keys rows by HMAC digests of the email and IP instead of the plain values. Rate limits and code lookups only ever need equality.
- Rate limits are check-then-insert without a lock, so a burst of parallel requests can overshoot a limit by the number in flight. Fine for limits whose job is stopping mail abuse; `GET_LOCK()` per address digest would close it. If the limit query itself fails, the request is refused.
- The session is the plugin's own signed cookie, not a WordPress login. Logging buyers into WordPress users would turn a code for an address that also belongs to an administrator into an admin login, so the two are kept apart. Orders from signed-in buyers are guest orders unless the buyer also has a WooCommerce account.
- The form posts back to its own page and is handled on `template_redirect` at priority 5, ahead of the catalog gate, the way `wp-login.php` handles its own POSTs.
- Origin matching, the client IP lookup and the return URL read live in `Request_Guard`, so `Sign_In_Form` only drives the steps and renders them.

### Attribution

- The referral cookie is only a hint carried through the anonymous part of the visit. The order's rep always comes from `gaa_identities`, which is why a test edits the cookie at checkout time and expects no change.
- Before verification the most recent rep link wins; after verification the first bound rep wins. The first rule follows the visitor's latest intent, the second keeps a rep's credit from being taken by someone sending a link to an existing buyer. Both are one-line changes if the business wants something else.

### Gating

- The page gate asks `CartCheckoutUtils::is_cart_page()` / `is_checkout_page()` instead of `is_cart()` / `is_checkout()`. The latter also return true whenever the `WOOCOMMERCE_CART` or `WOOCOMMERCE_CHECKOUT` constants are defined, which happens during cart and checkout AJAX work. WooCommerce's own `wc_get_cart_url()` makes the same choice for the same reason.
- `wc-ajax` is refused wholesale before sign-in instead of per action. A per-action list would need updating with every WooCommerce release; nothing a visitor needs before sign-in goes through wc-ajax.
- Access is `manage_woocommerce` or a verified session. There is no meta capability per identity because identities are not WordPress objects; `gaa_catalog_access` is the extension point.

### Webhook

- The signature has no timestamp. Replays are harmless because the event ID was already claimed, and `gaa_processed_events` is never pruned. If it ever is pruned, add a signed timestamp and reject old ones, or a replay after pruning would apply again.
- An event refused with 404 or 422 releases its event ID, on the assumption that the sender will retry once the buyer or rep exists. A 503 releases it too.
- Event IDs are limited to 92 characters, not the 128 of the `gaa_processed_events` column, so that `webhook:<event_id>` fits the 100-character `actor` column of the audit log. The other way out was widening that column, which needs a schema version bump and an upgrade step.
- Schema validation of the body runs before the signature check, because that is the order WordPress's REST server applies them (`dispatch()` calls `has_valid_params()` before `respond_to_request()`). An unsigned request with a malformed body gets 400 instead of 401. It learns nothing the public REST index does not already show.

### Admin screen

- It lists a buyer's orders from the `order.attributed` audit entries, not from order meta. `wc_get_orders()` with `meta_query` works under HPOS but raises `doing_it_wrong` on posts storage; the second test pass caught it.
- Reassignment nonces are bound to the identity ID, so a nonce taken from one buyer's page does not work on another.

### Plumbing

- The upgrade check runs on every `init`. It costs one autoloaded option read. Two requests arriving right after a deploy can both run `dbDelta()`. Since dbDelta only adds what is missing, the expected worst case is a "table already exists" error in the log, but that was not tested under real concurrency. A busy store might want a lock, as WooCommerce's own installer has.
- Data classes use `global $wpdb` instead of taking it in the constructor. WPCS only recognises prepared queries on a variable literally named `$wpdb`, and the injection bought nothing in tests. `Schema::table()` is the one place that adds the prefix to a table name.
- The test bootstrap sets `wp_mail_from`. wp-env's test site runs on `localhost`, WordPress's default sender becomes `wordpress@localhost`, and `is_email()` rejects it, so every `wp_mail()` would fail. The same is true of the wp-env development site, which is why `dev/mailpit.php` sets a sender too.
- wp-env 11 prints a deprecation warning: starting the separate tests environment by default will go away. When it does, `composer test` needs a second config file started with `--config`, as wp-env's README describes.

## Quirks worth knowing

Most of them come from running many requests in one PHPUnit process, where production runs one request per process:

- Switching `woocommerce_custom_orders_table_enabled` inside a test does not switch WooCommerce cleanly. The Store API kept writing through the HPOS data store after the option said posts. Hence one storage per PHPUnit process, picked in the bootstrap from `GAA_TEST_ORDER_STORAGE`, and two passes in `composer test`.
- The Store API Checkout route keeps its order object in a property, and the WooCommerce session keeps the draft order ID. A second checkout test then resumed the first test's (rolled back) order. Tests reset `$wp_rest_server` and that session key.
- `CartCheckoutUtils` caches "is this the cart page" in static properties for the life of the process. The gate tests clear them after each `go_to()`.
- The WordPress test bootstrap deletes every post after loading, so the sign-in page and WooCommerce pages that activation creates do not exist in tests. Tests that need them create them in `set_up()`.
- A `tear_down()` that throws before `parent::tear_down()` skips the ROLLBACK, and the next test's `START TRANSACTION` commits the leftovers. It made committed rows look like a leak and took a while to trace.
- `wp_mail()` fails on any wp-env site out of the box (the `wordpress@localhost` sender above).
- WordPress removes `STRICT_TRANS_TABLES` from the session SQL mode, so an oversized value is cut to the column width without an error. Code that must not lose data checks lengths itself.

And several about WordPress and WooCommerce themselves:

- Prices and orders do not only go through pages and REST. `?add-to-cart=` runs on `wp_loaded`, and `wc-ajax=get_variation` returns any variation's price by ID, both before any `template_redirect` gate. WooCommerce also registers the same storefront events for logged-out `admin-ajax.php`, which never fires `template_redirect` at all.
- `is_admin()` is true for `admin-ajax.php`, so it does not mean "a logged-in user on an admin screen". Use `is_admin() && ! wp_doing_ajax()` for that.
- Some WooCommerce blocks read the Store API controller in-process (`Hydration`), so they never pass through `rest_dispatch_request`, and they write what they read into interactivity state.
- `WP_REST_Request` merges JSON, form body and query string. An endpoint whose signature covers the body must read the body, not `$request['param']`.
- WordPress accepts `post_type[]` in the URL and then reports neither a post type archive nor anything `is_woocommerce()` recognises.
- A unique key on a `varchar` follows the table's collation, which on most sites is case-insensitive.

## Open questions

- Who is an approved buyer? Right now, anyone who can read mail at an address can sign in and see the catalog. A real B2B store probably wants one of: an allowlist of domains, admin approval of new identities, or "only addresses that arrived through a rep link". Each is a small change in `Sign_In_Form::verify_code()`, but it is a business decision.
- Should a buyer without a rep code be allowed in at all? Same decision, seen from the attribution side.
- Should a rep change also move open (unpaid) orders? Today no order changes rep after it was placed.
- How long should the audit log be kept? Nothing prunes it.
