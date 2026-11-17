<?php

namespace RescueAudit\Checks;

use Illuminate\Contracts\Config\Repository;
use RescueAudit\Support\Finding;
use RescueAudit\Support\Severity;

/**
 * Looks at the *effective* configuration of the app the command runs in.
 * Run it with the production .env (or APP_ENV=production php artisan rescue:audit)
 * to audit what production actually sees.
 */
class DangerousConfigCheck implements Check
{
    public function __construct(protected Repository $config, protected string $basePath)
    {
    }

    public function name(): string
    {
        return 'config';
    }

    public function description(): string
    {
        return 'Debug mode, missing APP_KEY, insecure session/cookie and CORS settings.';
    }

    public function run(): array
    {
        $f = [];
        $env = (string) $this->config->get('app.env', 'production');
        $isProd = $env === 'production';

        if ($this->config->get('app.debug')) {
            $f[] = new Finding(
                $this->name(),
                $isProd ? Severity::Critical : Severity::Medium,
                'APP_DEBUG is enabled'.($isProd ? ' in production' : " (APP_ENV={$env})"),
                'Debug mode leaks stack traces, environment variables and database credentials on any error page. '
                    .'Set APP_DEBUG=false in the production .env.',
                hours: 0.25,
            );
        }

        if (! $isProd && $this->looksLikeDeployed()) {
            $f[] = new Finding(
                $this->name(),
                Severity::Medium,
                "APP_ENV is \"{$env}\" but the app looks deployed",
                'Several packages (Telescope, Debugbar, Ignition) enable themselves when APP_ENV is not production. '
                    .'Set APP_ENV=production on the server.',
                hours: 0.25,
            );
        }

        $key = (string) $this->config->get('app.key', '');
        if ($key === '' || $key === 'SomeRandomString') {
            $f[] = new Finding(
                $this->name(),
                Severity::Critical,
                'APP_KEY is empty',
                'Sessions, cookies, encrypted casts and signed URLs all depend on APP_KEY. Run php artisan key:generate '
                    .'(note: existing encrypted data becomes unreadable).',
                hours: 0.5,
            );
        }

        if ($isProd) {
            if (! $this->config->get('session.secure')) {
                $f[] = new Finding(
                    $this->name(),
                    Severity::High,
                    'Session cookie is not marked Secure (SESSION_SECURE_COOKIE)',
                    'Without the Secure flag the session cookie can be sent over plain HTTP and intercepted. '
                        .'Set SESSION_SECURE_COOKIE=true once the site is served over HTTPS.',
                    hours: 0.25,
                );
            }

            if (! $this->config->get('session.http_only', true)) {
                $f[] = new Finding(
                    $this->name(),
                    Severity::High,
                    'Session cookie is not HttpOnly',
                    'JavaScript can read the session cookie, which turns any XSS bug into full session theft. '
                        .'Set session.http_only to true.',
                    hours: 0.25,
                );
            }

            $sameSite = $this->config->get('session.same_site');
            if ($sameSite === null || $sameSite === 'none') {
                $f[] = new Finding(
                    $this->name(),
                    Severity::Medium,
                    'Session cookie SameSite is "'.($sameSite ?? 'null').'"',
                    'SameSite=lax (default) protects most forms against CSRF from other sites. Only use "none" for '
                        .'cross-site embeds, and then always with Secure.',
                    hours: 0.25,
                );
            }

            if ($this->config->get('session.driver') === 'array') {
                $f[] = new Finding(
                    $this->name(),
                    Severity::High,
                    'Session driver is "array" in production',
                    'The array driver does not persist sessions, so logins silently fail. Use database, redis or file.',
                    hours: 0.5,
                );
            }
        }

        $origins = (array) $this->config->get('cors.allowed_origins', []);
        $supportsCreds = (bool) $this->config->get('cors.supports_credentials', false);
        if (in_array('*', $origins, true) && $supportsCreds) {
            $f[] = new Finding(
                $this->name(),
                Severity::High,
                'CORS allows every origin (*) together with credentials',
                'Any website can make authenticated requests to the API from the victim\'s browser. List the real '
                    .'front-end origins in config/cors.php → allowed_origins.',
                hours: 0.5,
            );
        } elseif (in_array('*', $origins, true) && $this->config->get('cors.paths')) {
            $f[] = new Finding(
                $this->name(),
                Severity::Low,
                'CORS allows every origin (*)',
                'Fine for a truly public API; otherwise restrict allowed_origins to the front-end domains.',
                hours: 0.25,
            );
        }

        if ($this->config->get('mail.default') === 'log' && $isProd) {
            $f[] = new Finding(
                $this->name(),
                Severity::Medium,
                'Mail driver is "log" in production',
                'Password resets and notifications are written to the log instead of being sent. Configure smtp/ses/etc.',
                hours: 0.5,
            );
        }

        if ($this->config->get('database.default') === 'sqlite' && $isProd) {
            $f[] = new Finding(
                $this->name(),
                Severity::Low,
                'Production database is SQLite',
                'Acceptable for small apps, but check that the file is outside the web root, backed up, and not '
                    .'committed to git.',
                hours: 0.5,
            );
        }

        if (file_exists($this->basePath.'/.env') && is_readable($this->basePath.'/public/.env')) {
            $f[] = new Finding(
                $this->name(),
                Severity::Critical,
                '.env file is inside the public/ directory',
                'It can be downloaded by anyone. Move it to the project root and rotate every secret in it.',
                hours: 1.0,
            );
        }

        return $f;
    }

    protected function looksLikeDeployed(): bool
    {
        $url = (string) $this->config->get('app.url', '');

        return $url !== '' && ! preg_match('~^https?://(localhost|127\.0\.0\.1|.*\.test|.*\.local)(:\d+)?/?$~', $url);
    }
}
