<?php

namespace RescueAudit;

use Illuminate\Contracts\Container\Container;
use RescueAudit\Checks\Check;
use RescueAudit\Checks\DangerousConfigCheck;
use RescueAudit\Checks\DependenciesCheck;
use RescueAudit\Checks\MassAssignmentCheck;
use RescueAudit\Checks\NPlusOneCheck;
use RescueAudit\Checks\UnprotectedRoutesCheck;
use RescueAudit\Support\Finding;
use RescueAudit\Support\Report;

class Auditor
{
    public function __construct(protected Container $app)
    {
    }

    /**
     * @param  array<int,string>  $only  check names to run (empty = all)
     * @param  array<int,string>  $except  check names to skip
     */
    public function run(array $only = [], array $except = [], ?callable $onCheck = null): Report
    {
        $config = $this->app['config']->get('rescue-audit', []);
        $findings = [];
        $checks = [];

        foreach ($config['checks'] ?? [] as $class) {
            $check = $this->make($class, $config);

            if (($only && ! in_array($check->name(), $only, true)) || in_array($check->name(), $except, true)) {
                continue;
            }

            try {
                $result = $check->run();
            } catch (\Throwable $e) {
                $result = [new Finding(
                    $check->name(),
                    \RescueAudit\Support\Severity::Low,
                    'Check "'.$check->name().'" crashed: '.get_class($e),
                    $e->getMessage(),
                    hours: 0,
                )];
            }

            $checks[$check->name()] = [
                'name' => $check->name(),
                'description' => $check->description(),
                'findings' => count($result),
            ];
            $findings = array_merge($findings, $result);

            if ($onCheck) {
                $onCheck($check, $result);
            }
        }

        $meta = [
            'app' => (string) $this->app['config']->get('app.name', 'Laravel app'),
            'env' => (string) $this->app['config']->get('app.env', ''),
            'laravel' => $this->app->version(),
            'php' => PHP_VERSION,
            'generated_at' => date('c'),
            'tool' => 'laravel-rescue-audit',
        ];

        return new Report($findings, $checks, $meta, $config['hours'] ?? []);
    }

    protected function make(string $class, array $config): Check
    {
        $base = $this->app->basePath();

        return match ($class) {
            UnprotectedRoutesCheck::class => new UnprotectedRoutesCheck($this->app['router'], $config),
            DangerousConfigCheck::class => new DangerousConfigCheck($this->app['config'], $base),
            MassAssignmentCheck::class => new MassAssignmentCheck($base, $config['model_paths'] ?? ['app/Models', 'app']),
            DependenciesCheck::class => new DependenciesCheck($base),
            NPlusOneCheck::class => new NPlusOneCheck($base, $config['view_paths'] ?? ['resources/views']),
            default => $this->app->make($class),
        };
    }
}
