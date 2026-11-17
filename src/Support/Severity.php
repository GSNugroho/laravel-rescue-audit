<?php

namespace RescueAudit\Support;

enum Severity: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    /** Points subtracted from the 100-point score per finding. */
    public function penalty(): int
    {
        return match ($this) {
            self::Critical => 15,
            self::High => 8,
            self::Medium => 4,
            self::Low => 1,
        };
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function rank(): int
    {
        return match ($this) {
            self::Critical => 0,
            self::High => 1,
            self::Medium => 2,
            self::Low => 3,
        };
    }
}
