<?php

namespace RescueAudit\Checks;

use RescueAudit\Support\Finding;
use RescueAudit\Support\Severity;
use RescueAudit\Support\Files;

/**
 * Heuristic N+1 detector: inside a Blade @foreach ($items as $item) it looks
 * for $item->relation->field (or $item->relation()->...) and reports views
 * whose loop variable's relation is never eager-loaded anywhere in app/ via
 * ->with('relation') / ->load('relation') / 'relation.' prefix.
 */
class NPlusOneCheck implements Check
{
    public function __construct(
        protected string $basePath,
        protected array $viewPaths = ['resources/views'],
        protected array $appPaths = ['app'],
    ) {
    }

    public function name(): string
    {
        return 'nplus1';
    }

    public function description(): string
    {
        return 'Blade loops that access relations which are never eager-loaded.';
    }

    public function run(): array
    {
        $eager = $this->eagerLoadedRelations();
        $relations = $this->declaredRelations();
        $suspects = [];

        foreach (Files::find($this->basePath, $this->viewPaths, '.blade.php') as $path) {
            $src = file_get_contents($path);
            $rel = Files::relative($this->basePath, $path);

            // @foreach ($posts as $post) / @foreach($posts as $key => $post) / @forelse
            if (! preg_match_all('/@fore(?:ach|lse)\s*\(\s*\$\w+(?:->\w+(?:\(\))?)*\s+as\s+(?:\$\w+\s*=>\s*)?\$(\w+)\s*\)/', $src, $loops, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($loops[1] as [$var, $offset]) {
                // Rough loop body: from the @foreach until the matching @endforeach/@endforelse.
                $end = $this->findLoopEnd($src, $offset);
                $body = substr($src, $offset, $end - $offset);

                // $post->author->name  or  $post->comments->count()  (skip ->count on scalars is fine)
                if (preg_match_all('/\$'.preg_quote($var, '/').'->(\w+)(?:->|\(\)->)/', $body, $m)) {
                    foreach (array_unique($m[1]) as $relation) {
                        // Only names that are declared as relation methods on some model count;
                        // everything else ($invoice->due_date->format(), $log->action->label()) is
                        // a cast, accessor or enum.
                        if ($this->isNotARelation($relation) || ! isset($relations[$relation])) {
                            continue;
                        }
                        if (! isset($eager[$relation])) {
                            $suspects[$rel][] = "\${$var}->{$relation}";
                        }
                    }
                }
            }
        }

        if (! $suspects) {
            return [];
        }

        $items = [];
        foreach ($suspects as $view => $accesses) {
            $items[] = $view.' → '.implode(', ', array_unique($accesses));
        }

        return [
            new Finding(
                $this->name(),
                count($items) >= 5 ? Severity::High : Severity::Medium,
                count($items).' view(s) access a relation inside a loop that is never eager-loaded',
                'Each iteration fires an extra query, so a page with 100 rows runs 100+ queries. Add ->with(\'relation\') '
                    .'to the query in the controller, or $collection->load(...). Verify with Laravel Debugbar or '
                    .'Model::preventLazyLoading() in local. (Heuristic: the relation name was not found in any '
                    .'with()/load() call under app/.)',
                $items,
            ),
        ];
    }

    /**
     * Method names under app/ whose body returns an Eloquent relation
     * (hasMany, belongsTo, morphTo...). Only these can be lazy-loaded.
     *
     * @return array<string,true>
     */
    protected function declaredRelations(): array
    {
        $names = [];
        $pattern = '/function\s+(\w+)\s*\([^)]*\)\s*(?::\s*[\\\\\w|?]+)?\s*\{(?:(?!function\s)[^{}]|\{[^{}]*\})*?return\s+\$this->(?:hasOne|hasMany|belongsTo|belongsToMany|hasOneThrough|hasManyThrough|morphOne|morphMany|morphTo|morphToMany|morphedByMany|hasOneOfMany|latestOfMany|oldestOfMany|ofMany)\s*\(/s';

        foreach (Files::find($this->basePath, $this->appPaths, '.php') as $path) {
            $src = file_get_contents($path);
            if (! str_contains($src, '$this->has') && ! str_contains($src, '$this->belongsTo') && ! str_contains($src, '$this->morph')) {
                continue;
            }
            if (preg_match_all($pattern, $src, $m)) {
                foreach ($m[1] as $n) {
                    $names[$n] = true;
                }
            }
        }

        return $names;
    }

    /** @return array<string,true> relation names seen in with()/load()/withCount() anywhere in app/. */
    protected function eagerLoadedRelations(): array
    {
        $names = [];
        foreach (Files::find($this->basePath, $this->appPaths, '.php') as $path) {
            $src = file_get_contents($path);
            if (preg_match_all('/(?:->|::)(?:with|load|loadMissing|withCount|withWhereHas)\s*\(([^;]*?)\)/s', $src, $m)) {
                foreach ($m[1] as $args) {
                    if (preg_match_all('/[\'"]([\w.]+)[\'"]/', $args, $s)) {
                        foreach ($s[1] as $path) {
                            foreach (explode('.', $path) as $segment) {
                                $names[$segment] = true;
                            }
                        }
                    }
                }
            }
            // protected $with = ['author'];
            if (preg_match_all('/\$with\s*=\s*\[([^\]]*)\]/', $src, $m)) {
                foreach ($m[1] as $args) {
                    if (preg_match_all('/[\'"](\w+)[\'"]/', $args, $s)) {
                        foreach ($s[1] as $n) {
                            $names[$n] = true;
                        }
                    }
                }
            }
        }

        return $names;
    }

    protected function isNotARelation(string $name): bool
    {
        // Common non-relation accessors on models/collections.
        return in_array($name, [
            'id', 'name', 'title', 'created_at', 'updated_at', 'deleted_at', 'email', 'slug', 'status',
            'attributes', 'exists', 'wasRecentlyCreated', 'timestamps', 'pivot', 'first', 'last', 'count',
            'format', 'diffForHumans', 'toArray', 'toJson', 'isEmpty', 'isNotEmpty', 'map', 'filter',
            'sum', 'avg', 'max', 'min', 'keys', 'values', 'each', 'pluck', 'where', 'sortBy', 'take',
            'image', 'photo', 'thumbnail', 'price', 'amount', 'total', 'date', 'time', 'description', 'body', 'content',
        ], true) || str_starts_with($name, 'is_') || str_starts_with($name, 'has_');
    }

    protected function findLoopEnd(string $src, int $start): int
    {
        $depth = 0;
        $offset = $start;
        $len = strlen($src);
        while ($offset < $len && preg_match('/@(fore(?:ach|lse)|endfore(?:ach|lse))\b/', $src, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $tag = $m[1][0];
            $pos = $m[0][1];
            $offset = $pos + strlen($m[0][0]);
            if (str_starts_with($tag, 'fore')) {
                $depth++;
            } else {
                $depth--;
                if ($depth === 0) {
                    return $pos;
                }
            }
        }

        return $len;
    }

}
