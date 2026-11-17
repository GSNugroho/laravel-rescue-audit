<?php

namespace RescueAudit\Support;

/**
 * Tiny dependency-free file finder (replaces symfony/finder for our needs).
 */
class Files
{
    /**
     * @param  array<int,string>  $dirs  relative to $basePath
     * @param  string  $suffix  e.g. ".php" or ".blade.php"
     * @return array<int,string> absolute paths, sorted, deduplicated
     */
    public static function find(string $basePath, array $dirs, string $suffix, array $excludeDirs = ['vendor', 'node_modules', 'storage']): array
    {
        $found = [];
        foreach ($dirs as $d) {
            $dir = rtrim($basePath, '/\\').'/'.trim($d, '/\\');
            if (! is_dir($dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                    fn (\SplFileInfo $f) => ! ($f->isDir() && in_array($f->getFilename(), $excludeDirs, true))
                )
            );
            foreach ($it as $file) {
                /** @var \SplFileInfo $file */
                if ($file->isFile() && str_ends_with($file->getFilename(), $suffix)) {
                    $found[$file->getRealPath()] = true;
                }
            }
        }
        $paths = array_keys($found);
        sort($paths);

        return $paths;
    }

    public static function relative(string $basePath, string $path): string
    {
        $base = rtrim(realpath($basePath) ?: $basePath, '/\\');

        return ltrim(str_replace($base, '', $path), '/\\');
    }
}
