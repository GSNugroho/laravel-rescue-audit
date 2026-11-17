<?php

namespace RescueAudit\Support;

class Finding
{
    /**
     * @param  string  $check  Short name of the check that produced this finding.
     * @param  string  $title  One-line description of the problem.
     * @param  string  $detail  Why it matters and how to fix it.
     * @param  array<int, string>  $items  Affected routes / files / packages.
     * @param  float|null  $hours  Estimated fix hours (null = derived from severity).
     */
    public function __construct(
        public readonly string $check,
        public readonly Severity $severity,
        public readonly string $title,
        public readonly string $detail,
        public readonly array $items = [],
        public readonly ?float $hours = null,
    ) {
    }

    public function estimatedHours(array $hoursConfig): float
    {
        if ($this->hours !== null) {
            return $this->hours;
        }

        $base = (float) ($hoursConfig[$this->severity->value] ?? 0.5);
        $count = max(1, min(count($this->items), 10));

        return round($base * $count, 2);
    }

    public function toArray(array $hoursConfig = []): array
    {
        return [
            'check' => $this->check,
            'severity' => $this->severity->value,
            'title' => $this->title,
            'detail' => $this->detail,
            'items' => $this->items,
            'hours' => $this->estimatedHours($hoursConfig),
        ];
    }
}
