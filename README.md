# Gated Access & Referral Attribution for WooCommerce

A WooCommerce plugin for stores that sell only to approved buyers. Visitors arrive through a sales rep's referral link, sign in with a one-time code sent by email, and only then see the catalog and prices. Every order records, on the server, which rep brought the buyer.

Work in progress. `NOTES.md` lists what is built so far.

## Requirements

- WordPress 7.1, WooCommerce 11.2, PHP 8.1 or newer. Those are the tested versions; older ones may work but have not been tried.

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
