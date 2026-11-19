# MHM Slotiva

Hourly service appointment booking for WooCommerce. Customers pick a day and time inside the Checkout block and pay
for the order in the same step; the time is held while the payment is pending, so two customers can never book the
same time.

A WordPress.org plugin directory listing is coming. This repository holds the source.

- Requires PHP 8.1+, WordPress 7.0+, WooCommerce 11.2+ with the Checkout block.
- Slug / text domain: `mhm-slotiva` · namespace `MHM\Slotiva` · storage prefix `slotiva_` · constant prefix `SLOTIVA_`

## Build from source

The JavaScript in `build/` and the PHP dependencies in `vendor/` are generated, so they are not in the repository.

```
composer install --no-dev && npm ci && npm run build
```

## Development

```
composer install
composer check        # phpcs + phpstan + phpunit
npm ci
npm run test:js
```

PHPUnit needs the WordPress test library (`WP_TESTS_DIR`); `bin/install-wp-tests.sh` sets it up.

## For developers

Slotiva exposes PHP actions and filters and JavaScript filters (`wp.hooks`). They are documented in
[docs/hooks.md](docs/hooks.md); the documented set is a stable contract.
