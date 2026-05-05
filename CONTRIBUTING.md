# Contributing

Thanks for your interest. PRs and issues are welcome.

## Reporting bugs

Please open a GitHub issue with:
- Lunar core version
- Laravel version
- PHP version
- Tpay account type (sandbox / production)
- A minimal reproduction (the smallest payload or test that triggers the bug)
- Expected vs. actual behavior

If you suspect a security issue (e.g. signature bypass, leaked secrets in logs), do **not** open a public issue — email security@wizcode.pl.

## Proposing changes

1. Fork and create a topic branch off `main`.
2. Add or update tests covering the change. Tests live in `tests/Feature/` and use Orchestra Testbench.
3. Run the suite (skips e2e when sandbox creds aren't set):
   ```bash
   composer install
   composer test
   ```
4. Run the formatter:
   ```bash
   composer format
   ```
5. Run static analysis:
   ```bash
   composer analyse
   ```
6. Open a PR against `main` with a description of the change and any context (linked issue, tpay docs reference, etc.).

## Testing against the real tpay sandbox

Most useful tests hit the actual sandbox. Get sandbox credentials at [register.sandbox.tpay.com](https://register.sandbox.tpay.com/) and:

```bash
export TPAY_CLIENT_ID="..."
export TPAY_CLIENT_SECRET="..."
export TPAY_NOTIFICATION_SECRET="..."
composer test
```

Without these env vars, the suite skips e2e tests and runs unit-level checks only.

## Code style

We use [Laravel Pint](https://laravel.com/docs/pint) with strict rules (`declare_strict_types=1`, ordered imports). CI fails on style violations:

```bash
composer format        # auto-fix
composer format:check  # check only (CI mode)
```

## Versioning

[Semantic versioning](https://semver.org/). Breaking changes bump the major (after 1.0); new features bump the minor; bug fixes bump the patch.
