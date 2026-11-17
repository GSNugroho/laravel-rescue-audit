<?php

namespace RescueAudit\Checks;

use RescueAudit\Support\Finding;
use RescueAudit\Support\Severity;
use Symfony\Component\Process\Process;

/**
 * Reads composer.lock for end-of-life Laravel/PHP versions and runs
 * `composer audit` (if composer is available) for known advisories.
 */
class DependenciesCheck implements Check
{
    /** Laravel major → [security support end, severity when passed] (as of late 2026). */
    protected const LARAVEL_EOL = [
        5 => '2020-09-08',
        6 => '2022-09-06',
        7 => '2020-10-06',
        8 => '2023-01-24',
        9 => '2024-02-06',
        10 => '2025-02-04',
        11 => '2026-03-12',
        12 => '2027-02-24',
    ];

    /** PHP minor → security support end. */
    protected const PHP_EOL = [
        '7.4' => '2022-11-28',
        '8.0' => '2023-11-26',
        '8.1' => '2025-12-31',
        '8.2' => '2026-12-31',
        '8.3' => '2027-12-31',
        '8.4' => '2028-12-31',
    ];

    public function __construct(protected string $basePath, protected ?string $today = null)
    {
    }

    public function name(): string
    {
        return 'deps';
    }

    public function description(): string
    {
        return 'End-of-life framework/PHP versions and known security advisories.';
    }

    public function run(): array
    {
        $f = [];
        $today = $this->today ?? date('Y-m-d');

        $lock = $this->readLock();
        if ($lock === null) {
            $f[] = new Finding(
                $this->name(),
                Severity::Medium,
                'composer.lock not found',
                'Without a lock file every deploy may install different dependency versions. Commit composer.lock.',
                hours: 0.25,
            );

            return $f;
        }

        $laravel = $this->packageVersion($lock, 'laravel/framework');
        if ($laravel) {
            $major = (int) explode('.', ltrim($laravel, 'v'))[0];
            $eol = self::LARAVEL_EOL[$major] ?? null;
            if ($eol && $eol < $today) {
                $f[] = new Finding(
                    $this->name(),
                    $major <= 8 ? Severity::Critical : Severity::High,
                    "Laravel {$laravel} no longer receives security fixes (ended {$eol})",
                    'Known vulnerabilities in the framework will not be patched. Plan an upgrade path '
                        .'(Shift or manual) one major at a time; budget grows with each major skipped.',
                    hours: $major <= 8 ? 16.0 : 8.0,
                );
            }
        }

        $php = (string) ($lock['platform']['php'] ?? '');
        $runtime = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
        $eol = self::PHP_EOL[$runtime] ?? null;
        if ($eol && $eol < $today) {
            $f[] = new Finding(
                $this->name(),
                Severity::High,
                "Audit ran on PHP {$runtime}, which is end-of-life (ended {$eol})",
                'If the server runs the same version it receives no security fixes. Upgrade PHP'
                    .($php ? " (composer.json requires {$php})" : '').'.',
                hours: 4.0,
            );
        }

        $dev = [];
        foreach (['barryvdh/laravel-debugbar', 'laravel/telescope', 'spatie/laravel-ignition', 'laravel/dusk'] as $pkg) {
            if ($this->packageVersion($lock, $pkg, 'packages')) {
                $dev[] = $pkg;
            }
        }
        if ($dev) {
            $f[] = new Finding(
                $this->name(),
                Severity::Medium,
                count($dev).' debugging package(s) are in require (not require-dev)',
                'They ship to production and some expose routes (debugbar, telescope) that can leak data. Move them to '
                    .'require-dev and guard them with a gate.',
                $dev,
                hours: 0.5,
            );
        }

        $advisories = $this->composerAudit();
        if ($advisories === null) {
            // composer not runnable here; say so without penalising the score.
        } elseif ($advisories) {
            $f[] = new Finding(
                $this->name(),
                Severity::High,
                count($advisories).' package(s) have known security advisories (composer audit)',
                'Update the affected packages (composer update vendor/package) and re-run composer audit.',
                $advisories,
            );
        }

        return $f;
    }

    protected function readLock(): ?array
    {
        $path = rtrim($this->basePath, '/').'/composer.lock';
        if (! is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    protected function packageVersion(array $lock, string $name, ?string $section = null): ?string
    {
        $sections = $section ? [$section] : ['packages', 'packages-dev'];
        foreach ($sections as $s) {
            foreach ($lock[$s] ?? [] as $pkg) {
                if (($pkg['name'] ?? '') === $name) {
                    return (string) ($pkg['version'] ?? '');
                }
            }
        }

        return null;
    }

    /** @return array<int,string>|null  null = could not run */
    protected function composerAudit(): ?array
    {
        if (! class_exists(Process::class)) {
            return null;
        }

        try {
            $p = new Process(['composer', 'audit', '--format=json', '--no-interaction', '--locked'], $this->basePath);
            $p->setTimeout(60)->run();
        } catch (\Throwable) {
            return null;
        }

        $out = json_decode($p->getOutput(), true);
        if (! is_array($out)) {
            return null;
        }

        $items = [];
        foreach ($out['advisories'] ?? [] as $pkg => $list) {
            $titles = array_map(fn ($a) => $a['title'] ?? ($a['cve'] ?? 'advisory'), is_array($list) ? $list : []);
            $items[] = $pkg.': '.implode('; ', array_slice($titles, 0, 2));
        }
        foreach ($out['abandoned'] ?? [] as $pkg => $replacement) {
            $items[] = $pkg.' is abandoned'.(is_string($replacement) ? " (use {$replacement})" : '');
        }

        return $items;
    }
}
