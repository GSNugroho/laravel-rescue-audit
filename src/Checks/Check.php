<?php

namespace RescueAudit\Checks;

use RescueAudit\Support\Finding;

interface Check
{
    /** Short machine name, e.g. "routes". Used by --only / --except. */
    public function name(): string;

    /** Human description shown in the report. */
    public function description(): string;

    /** @return array<int, Finding> */
    public function run(): array;
}
