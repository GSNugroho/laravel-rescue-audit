<?php

namespace RescueAudit\Checks;

use RescueAudit\Support\Finding;
use RescueAudit\Support\Severity;
use RescueAudit\Support\Files;

/**
 * Finds Eloquent models that are fully unguarded ($guarded = []) or that
 * declare neither $fillable nor $guarded. Pure file scan, no autoloading.
 */
class MassAssignmentCheck implements Check
{
    public function __construct(protected string $basePath, protected array $modelPaths = ['app/Models', 'app'])
    {
    }

    public function name(): string
    {
        return 'models';
    }

    public function description(): string
    {
        return 'Eloquent models open to mass assignment.';
    }

    public function run(): array
    {
        $unguarded = [];
        $undeclared = [];
        $seen = [];

        foreach ($this->modelFiles() as $path) {
            if (isset($seen[$path])) {
                continue;
            }
            $seen[$path] = true;

            $src = file_get_contents($path);
            if (! $this->looksLikeModel($src)) {
                continue;
            }

            $rel = Files::relative($this->basePath, $path);

            if (preg_match('/\$guarded\s*=\s*\[\s*\]/', $src)) {
                $unguarded[] = $rel;
                continue;
            }

            if (! preg_match('/\$(fillable|guarded)\s*=/', $src) && ! str_contains($src, 'Model::unguard')) {
                $undeclared[] = $rel;
            }
        }

        $f = [];

        if ($unguarded) {
            $f[] = new Finding(
                $this->name(),
                Severity::High,
                count($unguarded).' model(s) set $guarded = [] (every column is mass-assignable)',
                'Combined with Model::create($request->all()) a user can set is_admin, role, user_id or price from the '
                    .'form. Replace with an explicit $fillable list, or always pass $request->validated() with only '
                    .'the intended keys.',
                $unguarded,
            );
        }

        if ($undeclared) {
            $f[] = new Finding(
                $this->name(),
                Severity::Low,
                count($undeclared).' model(s) declare neither $fillable nor $guarded',
                'Laravel defaults to guarding everything, so mass assignment throws. This usually means the code '
                    .'builds models attribute by attribute, which is safe but worth a look for consistency.',
                $undeclared,
                hours: 0.25,
            );
        }

        // Spot `::create($request->all())` / `->update($request->all())` which defeats guarding entirely.
        $allUsages = [];
        foreach ($this->phpFiles(['app']) as $path) {
            $src = file_get_contents($path);
            if (preg_match_all('/(?:create|update|fill|forceFill)\(\s*\$request->all\(\)\s*\)/', $src, $m)) {
                $rel = Files::relative($this->basePath, $path);
                $allUsages[] = $rel.' ('.count($m[0]).'x)';
            }
        }

        if ($allUsages) {
            $f[] = new Finding(
                $this->name(),
                Severity::High,
                count($allUsages).' file(s) pass $request->all() straight into create()/update()/fill()',
                'Whatever the client posts ends up in the database if the model allows it. Use '
                    .'$request->validated() or $request->only([...]).',
                $allUsages,
            );
        }

        return $f;
    }

    protected function looksLikeModel(string $src): bool
    {
        // Must be a concrete class that extends an Eloquent base class; traits, policies,
        // services etc. that merely import the Model class are not models.
        if (preg_match('/^\s*(?:abstract\s+)?trait\s+\w+/m', $src) || preg_match('/^\s*interface\s+\w+/m', $src)) {
            return false;
        }

        return (bool) preg_match('/\bclass\s+\w+\s+extends\s+\\\\?(?:[\w\\\\]+\\\\)?(Model|Authenticatable|Pivot|MorphPivot|User)\b/', $src);
    }

    /** @return array<int,string> */
    protected function modelFiles(): array
    {
        return $this->phpFiles($this->modelPaths);
    }

    /** @return array<int,string> */
    protected function phpFiles(array $dirs): array
    {
        return Files::find($this->basePath, $dirs, '.php');
    }
}
