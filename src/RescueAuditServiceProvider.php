<?php

namespace RescueAudit;

use Illuminate\Support\ServiceProvider;
use RescueAudit\Console\AuditCommand;

class RescueAuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/rescue-audit.php', 'rescue-audit');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'rescue-audit');

        if ($this->app->runningInConsole()) {
            $this->commands([AuditCommand::class]);

            $this->publishes([
                __DIR__.'/../config/rescue-audit.php' => config_path('rescue-audit.php'),
            ], 'rescue-audit-config');
        }
    }
}
