<?php

namespace RescueAudit\Support;

class Report
{
    /**
     * @param  array<int, Finding>  $findings
     * @param  array<string, array{name:string, description:string, findings:int}>  $checks
     */
    public function __construct(
        public readonly array $findings,
        public readonly array $checks,
        public readonly array $meta,
        public readonly array $hoursConfig,
    ) {
    }

    public function score(): int
    {
        $penalty = 0;
        foreach ($this->findings as $f) {
            // Each finding costs its base penalty, plus a little per extra affected item (capped).
            $extra = max(0, min(count($f->items), 10) - 1);
            $penalty += $f->severity->penalty() + (int) ceil($extra * $f->severity->penalty() / 10);
        }

        return max(0, 100 - $penalty);
    }

    public function grade(): string
    {
        $s = $this->score();

        return match (true) {
            $s >= 90 => 'A',
            $s >= 75 => 'B',
            $s >= 60 => 'C',
            $s >= 40 => 'D',
            default => 'F',
        };
    }

    public function totalHours(): float
    {
        return round(array_sum(array_map(
            fn (Finding $f) => $f->estimatedHours($this->hoursConfig),
            $this->findings
        )), 1);
    }

    /** @return array<string, int> */
    public function countBySeverity(): array
    {
        $out = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
        foreach ($this->findings as $f) {
            $out[$f->severity->value]++;
        }

        return $out;
    }

    /** @return array<int, Finding> sorted critical → low */
    public function sortedFindings(): array
    {
        $f = $this->findings;
        usort($f, fn (Finding $a, Finding $b) => $a->severity->rank() <=> $b->severity->rank());

        return $f;
    }

    public function toArray(): array
    {
        return [
            'meta' => $this->meta,
            'score' => $this->score(),
            'grade' => $this->grade(),
            'total_hours' => $this->totalHours(),
            'summary' => $this->countBySeverity(),
            'checks' => array_values($this->checks),
            'findings' => array_map(fn (Finding $f) => $f->toArray($this->hoursConfig), $this->sortedFindings()),
        ];
    }
}
