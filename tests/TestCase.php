<?php

namespace RescueAudit\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use RescueAudit\RescueAuditServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [RescueAuditServiceProvider::class];
    }

    protected function fixturePath(string $path = ''): string
    {
        return __DIR__.'/fixtures/badapp'.($path ? '/'.ltrim($path, '/') : '');
    }
}
