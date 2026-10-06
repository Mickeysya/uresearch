<?php

namespace Tests\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Every Blade view in every module, at any depth.
 *
 * Not glob(): PHP's glob() has no `**`, so `views/**\/*.blade.php` meant
 * exactly one folder down, and 46 nested views (admin/*, candidacy/appeal/*,
 * workstation/cgs/*...) were never scanned by the guards that used it.
 */
trait FindsViews
{
    /** @return array<string, string> repo-relative name => absolute path, sorted */
    protected function bladeViews(string $basename = '*.blade.php'): array
    {
        $views = [];

        foreach (glob(base_path('app/Modules/*/Resources/views'), GLOB_ONLYDIR) as $root) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if (fnmatch($basename, $file->getFilename())) {
                    $views[str_replace(base_path().'/', '', $file->getPathname())] = $file->getPathname();
                }
            }
        }

        ksort($views);

        return $views;
    }
}
