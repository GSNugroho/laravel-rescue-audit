<?php

namespace RescueAudit\Tests;

use Illuminate\Support\Facades\Route;
use RescueAudit\Checks\DangerousConfigCheck;
use RescueAudit\Checks\DependenciesCheck;
use RescueAudit\Checks\MassAssignmentCheck;
use RescueAudit\Checks\NPlusOneCheck;
use RescueAudit\Checks\UnprotectedRoutesCheck;
use RescueAudit\Support\Finding;
use RescueAudit\Support\Severity;

class ChecksTest extends TestCase
{
    /** @return array<string, Finding> keyed by title */
    protected function byTitle(array $findings): array
    {
        $out = [];
        foreach ($findings as $f) {
            $out[$f->title] = $f;
        }

        return $out;
    }

    public function test_unprotected_write_routes_are_flagged(): void
    {
        Route::post('/orders', fn () => 'x');                              // bad
        Route::delete('/orders/{id}', fn () => 'x')->middleware('auth');    // ok
        Route::post('/login', fn () => 'x');                               // public by config
        Route::get('/orders', fn () => 'x')->middleware('throttle:60,1');  // ok (read)

        $check = new UnprotectedRoutesCheck($this->app['router'], config('rescue-audit'));
        $findings = $check->run();

        $this->assertNotEmpty($findings);
        $this->assertSame(Severity::Critical, $findings[0]->severity);
        $this->assertSame(['POST /orders'], $findings[0]->items);
    }

    public function test_protected_routes_produce_no_critical_finding(): void
    {
        Route::post('/orders', fn () => 'x')->middleware(['auth', 'throttle:60,1']);

        $check = new UnprotectedRoutesCheck($this->app['router'], config('rescue-audit'));
        $severities = array_map(fn (Finding $f) => $f->severity, $check->run());

        $this->assertNotContains(Severity::Critical, $severities);
    }

    public function test_token_routes_and_asset_routes_are_not_critical(): void
    {
        Route::post('/isi-data/{token}', fn () => 'x');            // token-protected → medium, not critical
        Route::post('/atur-ulang-sandi/{token}', fn () => 'x');
        Route::get('/flux/flux.js', fn () => 'x');                 // package asset → ignored
        Route::get('/livewire-abc123/js/{component}.js', fn () => 'x');

        $findings = $this->byTitle((new UnprotectedRoutesCheck($this->app['router'], config('rescue-audit')))->run());
        $titles = array_keys($findings);

        $this->assertContains('2 write route(s) rely only on a URL token instead of auth middleware', $titles);
        $this->assertFalse((bool) preg_grep('/without any auth middleware/', $titles));

        foreach ($findings as $f) {
            foreach ($f->items as $item) {
                $this->assertStringNotContainsString('/flux/', $item);
                $this->assertStringNotContainsString('/livewire-', $item);
            }
        }
    }

    public function test_dangerous_config_in_production(): void
    {
        config([
            'app.env' => 'production',
            'app.debug' => true,
            'app.key' => '',
            'session.secure' => false,
            'session.http_only' => true,
            'session.same_site' => 'lax',
            'cors.paths' => ['api/*'],
            'cors.allowed_origins' => ['*'],
            'cors.supports_credentials' => true,
        ]);

        $titles = array_keys($this->byTitle((new DangerousConfigCheck($this->app['config'], $this->fixturePath()))->run()));

        $this->assertContains('APP_DEBUG is enabled in production', $titles);
        $this->assertContains('APP_KEY is empty', $titles);
        $this->assertContains('Session cookie is not marked Secure (SESSION_SECURE_COOKIE)', $titles);
        $this->assertContains('CORS allows every origin (*) together with credentials', $titles);
    }

    public function test_safe_config_is_quiet(): void
    {
        config([
            'app.env' => 'production',
            'app.debug' => false,
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'session.secure' => true,
            'session.http_only' => true,
            'session.same_site' => 'lax',
            'session.driver' => 'file',
            'cors.allowed_origins' => ['https://app.example.com'],
            'mail.default' => 'smtp',
            'database.default' => 'mysql',
        ]);

        $this->assertSame([], (new DangerousConfigCheck($this->app['config'], $this->fixturePath()))->run());
    }

    public function test_mass_assignment_check(): void
    {
        $findings = $this->byTitle((new MassAssignmentCheck($this->fixturePath()))->run());

        $guarded = $findings['1 model(s) set $guarded = [] (every column is mass-assignable)'] ?? null;
        $this->assertNotNull($guarded);
        $this->assertSame(['app/Models/Post.php'], $guarded->items);

        $undeclared = $findings['1 model(s) declare neither $fillable nor $guarded'] ?? null;
        $this->assertNotNull($undeclared);
        $this->assertSame(['app/Models/Tag.php'], $undeclared->items);

        $all = $findings['1 file(s) pass $request->all() straight into create()/update()/fill()'] ?? null;
        $this->assertNotNull($all);
        $this->assertStringContainsString('PostController.php', $all->items[0]);
    }

    public function test_dependencies_check_reads_lock(): void
    {
        $findings = $this->byTitle((new DependenciesCheck($this->fixturePath(), '2026-11-17'))->run());
        $titles = array_keys($findings);

        $this->assertTrue((bool) preg_grep('/Laravel v8\.83\.27 no longer receives security fixes/', $titles));
        $this->assertContains('1 debugging package(s) are in require (not require-dev)', $titles);
    }

    public function test_n_plus_one_heuristic(): void
    {
        $findings = (new NPlusOneCheck($this->fixturePath()))->run();

        $this->assertCount(1, $findings);
        $item = $findings[0]->items[0];
        // "tags" is eager-loaded in the controller, author/comments/user are not.
        $this->assertStringContainsString('$post->author', $item);
        $this->assertStringContainsString('$post->comments', $item);
        $this->assertStringContainsString('$comment->user', $item);
        $this->assertStringNotContainsString('$post->tags', $item);
        // casts / enums are not relations
        $this->assertStringNotContainsString('published_at', $item);
        $this->assertStringNotContainsString('status', $item);
    }
}
