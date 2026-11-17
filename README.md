# Laravel Rescue Audit

One command that tells you how healthy an existing Laravel app is, before you agree to take it over.

```bash
composer require --dev gsnugroho/laravel-rescue-audit
php artisan rescue:audit                      # summary in the terminal
php artisan rescue:audit --format=html        # storage/app/rescue-audit/report-<date>.html
php artisan rescue:audit --format=md --output=AUDIT.md
php artisan rescue:audit --format=json --fail-under=70   # for CI
```

No database, no config required, nothing is sent anywhere. The report shows a 0–100 score, findings grouped by severity (Critical / High / Medium / Low), the affected routes, files and packages, and an estimated number of hours to fix each item.

See [docs/example-report.html](docs/example-report.html) for what the HTML output looks like.

## What it checks

| Check    | Looks for |
|----------|-----------|
| `routes` | POST/PUT/PATCH/DELETE routes without any `auth` middleware; routes without `throttle` |
| `config` | `APP_DEBUG=true`, empty `APP_KEY`, insecure session cookie (Secure / HttpOnly / SameSite), `array` session driver, CORS `*` with credentials, `log` mail driver and SQLite in production, `.env` inside `public/` |
| `models` | `$guarded = []`, models with neither `$fillable` nor `$guarded`, and `create($request->all())` / `update($request->all())` in `app/` |
| `deps`   | End-of-life Laravel and PHP versions, debugging packages (Debugbar, Telescope, Ignition, Dusk) in `require`, and known advisories via `composer audit` when Composer is available |
| `nplus1` | Blade `@foreach` loops that access a relation (`$post->author->name`) which is never eager-loaded with `with()` / `load()` / `$with` anywhere under `app/` |

Run a subset with `--only=routes,config` or skip one with `--except=nplus1`.

The `config` check reads the configuration the command runs with, so to audit production settings run it on the server (or with the production `.env`). Routes that are intentionally public (webhooks, contact forms) can be listed in `config/rescue-audit.php` → `public_routes` after `php artisan vendor:publish --tag=rescue-audit-config`.

## How the score works

Every finding subtracts points: Critical 15, High 8, Medium 4, Low 1, plus a little extra per additional affected item (capped at 10 items). Grade A is 90+, B 75+, C 60+, D 40+, otherwise F. Fix-hour estimates come from `config/rescue-audit.php` → `hours` and are meant as a starting point for a quote, not a promise.

## Limits

This is a static, heuristic scan. It cannot prove an app is secure and it will sometimes flag things that are fine (a public API without CORS restrictions, a view whose relation is eager-loaded through a repository it did not recognise). Treat the report as a checklist for a human review, not a verdict.

## Adding a check

Implement `RescueAudit\Checks\Check` (three methods: `name()`, `description()`, `run(): Finding[]`), add the class to `config/rescue-audit.php` → `checks`. Checks that need the container are resolved through `app()->make()`.

## Development

```bash
composer install
composer test
```

Tests use Orchestra Testbench and the fixture app in `tests/fixtures/badapp`.

## License

MIT
