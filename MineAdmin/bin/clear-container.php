<?php

declare(strict_types=1);

$containerPath = dirname(__DIR__) . '/runtime/container';

if (! file_exists($containerPath) && ! is_link($containerPath)) {
    return;
}

if (is_link($containerPath) || is_file($containerPath)) {
    if (! unlink($containerPath)) {
        throw new RuntimeException('Unable to remove container cache: ' . $containerPath);
    }

    return;
}

$entries = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($containerPath, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST,
);

foreach ($entries as $entry) {
    $removed = $entry->isDir() && ! $entry->isLink()
        ? rmdir($entry->getPathname())
        : unlink($entry->getPathname());

    if (! $removed) {
        throw new RuntimeException('Unable to remove container cache entry: ' . $entry->getPathname());
    }
}

if (! rmdir($containerPath)) {
    throw new RuntimeException('Unable to remove container cache: ' . $containerPath);
}
