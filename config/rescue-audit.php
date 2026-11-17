<?php

return [
    /*
    | Checks that run by default. Remove a class here (or use --only/--except)
    | to skip it.
    */
    'checks' => [
        \RescueAudit\Checks\UnprotectedRoutesCheck::class,
        \RescueAudit\Checks\DangerousConfigCheck::class,
        \RescueAudit\Checks\MassAssignmentCheck::class,
        \RescueAudit\Checks\DependenciesCheck::class,
        \RescueAudit\Checks\NPlusOneCheck::class,
    ],

    /*
    | Route URIs (or prefixes ending with *) that are expected to be public
    | and should not be flagged as unprotected.
    */
    'public_routes' => [
        'login', 'register', 'password/*', 'forgot-password', 'reset-password/*',
        'logout', 'sanctum/csrf-cookie', 'livewire/*', '_ignition/*', 'telescope/*',
        'horizon/*', 'up', 'webhooks/*', 'webhook/*',
    ],

    /*
    | Middleware names (or aliases) that count as "authenticated".
    */
    'auth_middleware' => ['auth', 'auth:sanctum', 'auth:api', 'auth.basic', 'signed', 'verified'],

    /*
    | Where to look for Eloquent models and Blade views.
    */
    'model_paths' => ['app/Models', 'app'],
    'view_paths' => ['resources/views'],

    /*
    | Fix-hour estimates per finding, by severity. Multiplied by the number of
    | affected items (capped) so the report can show a repair estimate.
    */
    'hours' => [
        'critical' => 1.5,
        'high' => 1.0,
        'medium' => 0.5,
        'low' => 0.25,
    ],
];
