<?php

/**
 * Every Livewire component view renders ONE element root — never a Blade
 * directive at the root.
 *
 * With @if at the root, Livewire emits its block marker comment before the
 * real root, and its wire:id / wire:snapshot injection lands on the first
 * element INSIDE the branch instead. The page still looks right, which is why
 * it shipped: the oversight dashboard's KPI row carried its identity on its
 * first tile, the lazy load morphed the wrong node, and the browser console
 * reported "Snapshot missing" on every visit.
 *
 * Partials are excluded — they are included into a root, not rendered as one.
 */
it('gives every Livewire view a single element root', function () {
    $root = dirname(__DIR__, 2).'/resources/views/livewire';
    $offenders = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        $path = $file->getPathname();

        if (! str_ends_with($path, '.blade.php') || str_contains($path, '/partials/')) {
            continue;
        }

        // What renders first once comments and @php blocks produce nothing.
        $source = (string) file_get_contents($path);
        $source = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);
        $source = (string) preg_replace('/@php\b.*?@endphp/s', '', $source);
        $source = ltrim($source);

        if (! str_starts_with($source, '<')) {
            $offenders[] = str_replace($root.'/', '', $path).' starts with: '.strtok($source, "\n");
        }
    }

    expect($offenders)->toBe([]);
});
