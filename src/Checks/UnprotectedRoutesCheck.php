<?php

namespace RescueAudit\Checks;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use RescueAudit\Support\Finding;
use RescueAudit\Support\Severity;

/**
 * Flags state-changing routes (POST/PUT/PATCH/DELETE) that have no auth middleware,
 * and routes without any rate limiting.
 */
class UnprotectedRoutesCheck implements Check
{
    public function __construct(protected Router $router, protected array $config = [])
    {
    }

    public function name(): string
    {
        return 'routes';
    }

    public function description(): string
    {
        return 'Write routes without authentication, and routes without rate limiting.';
    }

    public function run(): array
    {
        $publicPatterns = $this->config['public_routes'] ?? [];
        $authMiddleware = $this->config['auth_middleware'] ?? ['auth'];

        $noAuth = [];
        $tokenOnly = [];
        $noThrottle = [];
        $total = 0;

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            $uri = $route->uri();
            $methods = array_values(array_diff($route->methods(), ['HEAD', 'OPTIONS']));

            if ($this->isInternal($uri) || $this->isFrameworkRoute($route) || $this->matchesAny($uri, $publicPatterns)) {
                continue;
            }

            $total++;
            $middleware = $this->router->gatherRouteMiddleware($route);
            $middlewareNames = array_map(
                fn ($m) => is_string($m) ? $m : (is_object($m) ? get_class($m) : (string) $m),
                $middleware
            );
            // Also keep raw aliases ("auth:sanctum") that were declared on the route.
            $declared = $route->middleware();

            $label = implode('|', $methods).' /'.ltrim($uri, '/');

            $isWrite = (bool) array_intersect($methods, ['POST', 'PUT', 'PATCH', 'DELETE']);
            if ($isWrite && ! $this->hasAuth($middlewareNames, $declared, $authMiddleware)) {
                if ($this->looksTokenProtected($uri)) {
                    $tokenOnly[] = $label;
                } else {
                    $noAuth[] = $label;
                }
            }

            if (! $this->hasThrottle($middlewareNames, $declared)) {
                $noThrottle[] = $label;
            }
        }

        $findings = [];

        if ($noAuth) {
            $findings[] = new Finding(
                check: $this->name(),
                severity: Severity::Critical,
                title: count($noAuth).' write route(s) accept POST/PUT/PATCH/DELETE without any auth middleware',
                detail: 'Anyone on the internet can call these endpoints. Unless they are intentionally public '
                    .'(webhooks, contact forms), wrap them in Route::middleware(\'auth\') or auth:sanctum. '
                    .'Intentionally public routes can be listed in config/rescue-audit.php → public_routes.',
                items: $noAuth,
            );
        }

        if ($tokenOnly) {
            $findings[] = new Finding(
                check: $this->name(),
                severity: Severity::Medium,
                title: count($tokenOnly).' write route(s) rely only on a URL token instead of auth middleware',
                detail: 'Invitation, password-reset and "confirm" links are usually meant to be public, but the token is then '
                    .'the only protection. Make sure it is long and random (Str::random(40)+), expires, is single-use, '
                    .'and that the route is throttled so tokens cannot be guessed.',
                items: $tokenOnly,
                hours: 0.5,
            );
        }

        if ($noThrottle && $total > 0) {
            $ratio = count($noThrottle) / $total;
            $findings[] = new Finding(
                check: $this->name(),
                severity: $ratio > 0.9 ? Severity::Medium : Severity::Low,
                title: count($noThrottle).' of '.$total.' route(s) have no rate limiting (throttle)',
                detail: 'Without throttle middleware, login forms, OTP endpoints and search pages can be brute-forced '
                    .'or used to exhaust the server. Apply throttle:60,1 (or a named limiter via RateLimiter::for) '
                    .'to the web/api groups, and a stricter one to login/OTP routes.',
                items: array_slice($noThrottle, 0, 25),
                hours: 1.0,
            );
        }

        return $findings;
    }

    protected function looksTokenProtected(string $uri): bool
    {
        return (bool) preg_match('/\{(token|signature|hash|code|uuid|key|secret)\}/i', $uri);
    }

    protected function isInternal(string $uri): bool
    {
        return Str::startsWith($uri, ['_debugbar', '_ignition', 'sanctum/', 'livewire/', 'livewire-', 'flux/', 'telescope', 'horizon', 'pulse', 'storage/', 'vendor/'])
            || $uri === '/'
            || $uri === '{fallbackPlaceholder}';
    }

    /**
     * Routes registered by the framework or first-party packages (storage.local upload
     * route, Fortify, Sanctum, Horizon...) are not the app's responsibility.
     */
    protected function isFrameworkRoute(Route $route): bool
    {
        $name = (string) $route->getName();
        if ($name !== '' && Str::startsWith($name, ['storage.', 'sanctum.', 'horizon.', 'telescope.', 'pulse.', 'livewire.', 'flux.', 'filament.', 'ignition.'])) {
            return true;
        }

        $action = $route->getAction();
        $uses = $action['controller'] ?? ($action['uses'] ?? null);
        if (is_string($uses) && Str::startsWith($uses, ['Illuminate\\', 'Laravel\\', 'Livewire\\', 'Flux\\', 'Filament\\', 'Spatie\\LaravelIgnition\\'])) {
            return true;
        }

        return false;
    }

    protected function matchesAny(string $uri, array $patterns): bool
    {
        foreach ($patterns as $p) {
            if (Str::is($p, $uri)) {
                return true;
            }
        }

        return false;
    }

    protected function hasAuth(array $resolved, array $declared, array $authMiddleware): bool
    {
        foreach (array_merge($resolved, $declared) as $m) {
            $m = (string) $m;
            foreach ($authMiddleware as $a) {
                if ($m === $a || Str::startsWith($m, $a.':')) {
                    return true;
                }
            }
            if (Str::contains($m, ['\\Authenticate', 'Authenticate::class', 'EnsureEmailIsVerified', 'ValidateSignature'])) {
                return true;
            }
        }

        return false;
    }

    protected function hasThrottle(array $resolved, array $declared): bool
    {
        foreach (array_merge($resolved, $declared) as $m) {
            $m = (string) $m;
            if (Str::startsWith($m, 'throttle') || Str::contains($m, 'ThrottleRequests')) {
                return true;
            }
        }

        return false;
    }
}
