<?php

namespace RescueAudit\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\View\Factory as ViewFactory;
use RescueAudit\Auditor;
use RescueAudit\Support\Finding;
use RescueAudit\Support\Report;

class AuditCommand extends Command
{
    protected $signature = 'rescue:audit
        {--format=console : console, html, md or json}
        {--output= : Write the report to this file instead of stdout (html/md/json)}
        {--only= : Comma-separated check names to run (routes,config,models,deps,nplus1)}
        {--except= : Comma-separated check names to skip}
        {--fail-under= : Exit with code 1 if the score is below this number (for CI)}';

    protected $description = 'Audit this Laravel app for security and health problems and produce a report with a score.';

    public function handle(Auditor $auditor, ViewFactory $views): int
    {
        $only = array_filter(array_map('trim', explode(',', (string) $this->option('only'))));
        $except = array_filter(array_map('trim', explode(',', (string) $this->option('except'))));
        $format = strtolower((string) $this->option('format'));

        $quiet = $format !== 'console';

        $report = $auditor->run($only, $except, function ($check, array $findings) use ($quiet) {
            if ($quiet) {
                return;
            }
            $n = count($findings);
            $this->line(sprintf('  %s %-8s %s', $n ? '<fg=yellow>!</>' : '<fg=green>✓</>', $check->name(), $n ? "{$n} finding(s)" : 'ok'));
        });

        $out = match ($format) {
            'html' => $views->make('rescue-audit::report', ['report' => $report, 'data' => $report->toArray()])->render(),
            'md', 'markdown' => $this->markdown($report),
            'json' => json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            default => null,
        };

        if ($out !== null) {
            $path = $this->option('output') ?: $this->defaultPath($format);
            $dir = dirname($path);
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            file_put_contents($path, $out);
            $this->info("Report written to {$path} (score {$report->score()}/100, grade {$report->grade()})");
        } else {
            $this->renderConsole($report);
        }

        $threshold = $this->option('fail-under');
        if ($threshold !== null && $threshold !== '' && $report->score() < (int) $threshold) {
            $this->error("Score {$report->score()} is below --fail-under={$threshold}");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    protected function defaultPath(string $format): string
    {
        $ext = $format === 'markdown' ? 'md' : $format;

        return $this->laravel->storagePath('app/rescue-audit/report-'.date('Y-m-d-His').'.'.$ext);
    }

    protected function renderConsole(Report $report): void
    {
        $this->newLine();
        $this->line("  <options=bold>Rescue Audit</> — {$report->meta['app']} (Laravel {$report->meta['laravel']}, PHP {$report->meta['php']}, env {$report->meta['env']})");
        $this->line(sprintf('  Score <options=bold>%d/100</> (grade %s) · est. fix time <options=bold>%s h</>', $report->score(), $report->grade(), $report->totalHours()));
        $this->newLine();

        if (! $report->findings) {
            $this->info('  No findings. Nice.');

            return;
        }

        foreach ($report->sortedFindings() as $f) {
            $color = match ($f->severity->value) {
                'critical' => 'red',
                'high' => 'yellow',
                'medium' => 'blue',
                default => 'gray',
            };
            $this->line(sprintf('  <fg=%s;options=bold>[%s]</> %s  <fg=gray>(~%sh, %s)</>', $color, strtoupper($f->severity->value), $f->title, $f->estimatedHours($report->hoursConfig), $f->check));
            $this->line('    <fg=gray>'.wordwrap($f->detail, 100, "\n    ").'</>');
            foreach (array_slice($f->items, 0, 8) as $item) {
                $this->line('    - '.$item);
            }
            if (count($f->items) > 8) {
                $this->line('    - … and '.(count($f->items) - 8).' more (see --format=html)');
            }
            $this->newLine();
        }
    }

    protected function markdown(Report $report): string
    {
        $m = $report->meta;
        $s = $report->countBySeverity();
        $md = "# Rescue Audit — {$m['app']}\n\n";
        $md .= "**Score: {$report->score()}/100 (grade {$report->grade()})** · Estimated fix time: **{$report->totalHours()} h**  \n";
        $md .= "Laravel {$m['laravel']} · PHP {$m['php']} · env `{$m['env']}` · generated {$m['generated_at']}\n\n";
        $md .= "| Critical | High | Medium | Low |\n|---|---|---|---|\n| {$s['critical']} | {$s['high']} | {$s['medium']} | {$s['low']} |\n\n";

        foreach ($report->sortedFindings() as $f) {
            /** @var Finding $f */
            $md .= "## [{$f->severity->label()}] {$f->title}\n\n";
            $md .= "_Check: {$f->check} · est. {$f->estimatedHours($report->hoursConfig)} h_\n\n{$f->detail}\n\n";
            foreach ($f->items as $item) {
                $md .= "- `{$item}`\n";
            }
            $md .= "\n";
        }

        $md .= "---\nChecks run: ".implode(', ', array_map(fn ($c) => $c['name'], $report->checks))."\n";

        return $md;
    }
}
