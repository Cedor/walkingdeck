<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
$excludedDirectories = ['vendor', '.git', 'node_modules'];
$failed = false;

foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php' || $file->getFilename() === '_ide_helper.php') {
        continue;
    }

    $relativePath = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    foreach ($excludedDirectories as $directory) {
        if (strpos($relativePath, $directory . '/') === 0) {
            continue 2;
        }
    }

    $command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname());
    passthru($command, $exitCode);
    if ($exitCode !== 0) {
        $failed = true;
    }
}

exit($failed ? 1 : 0);
