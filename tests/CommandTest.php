<?php

namespace RescueAudit\Tests;

use Illuminate\Support\Facades\Route;

class CommandTest extends TestCase
{
    public function test_console_output_and_score(): void
    {
        Route::post('/orders', fn () => 'x');
        config(['app.env' => 'production', 'app.debug' => true]);

        $this->artisan('rescue:audit', ['--only' => 'routes,config'])
            ->expectsOutputToContain('Score')
            ->expectsOutputToContain('[CRITICAL]')
            ->assertExitCode(0);
    }

    public function test_fail_under_returns_failure(): void
    {
        Route::post('/orders', fn () => 'x');
        config(['app.env' => 'production', 'app.debug' => true]);

        $this->artisan('rescue:audit', ['--only' => 'routes,config', '--fail-under' => 100])
            ->assertExitCode(1);
    }

    public function test_html_report_is_written(): void
    {
        Route::post('/orders', fn () => 'x');
        $path = sys_get_temp_dir().'/rescue-audit-test-'.uniqid().'.html';

        $this->artisan('rescue:audit', ['--only' => 'routes', '--format' => 'html', '--output' => $path])
            ->assertExitCode(0);

        $html = file_get_contents($path);
        $this->assertStringContainsString('Rescue Audit', $html);
        $this->assertStringContainsString('POST /orders', $html);
        $this->assertStringContainsString('Grade', $html);
        @unlink($path);
    }

    public function test_json_report(): void
    {
        $path = sys_get_temp_dir().'/rescue-audit-test-'.uniqid().'.json';
        $this->artisan('rescue:audit', ['--only' => 'routes', '--format' => 'json', '--output' => $path])->assertExitCode(0);

        $json = json_decode(file_get_contents($path), true);
        $this->assertArrayHasKey('score', $json);
        $this->assertArrayHasKey('findings', $json);
        @unlink($path);
    }
}
