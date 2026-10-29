<?php

/**
 * Copies the plugin's third-party packages into plugin/vendor-prefixed/, under the plugin's own namespace.
 *
 * Another extension, or a later Joomla, may load its own composer/semver; with the prefix the plugin's copy can
 * never clash with it. Runs after composer install and update.
 *
 *   php tools/prefix-vendor.php
 */

declare(strict_types=1);

$root   = \dirname(__DIR__);
$target = $root . '/plugin/vendor-prefixed';
$prefix = 'JoomlaDependencies\\Vendor\\';

// Package => [source folder in the package, namespace to prefix].
$packages = [
    'composer/semver' => ['src', 'Composer\\Semver'],
];

removeDir($target);

foreach ($packages as $package => [$sourceDir, $namespace]) {
    $source = "$root/vendor/$package/$sourceDir";

    if (!is_dir($source)) {
        fwrite(STDERR, "$package is not installed; run composer install first.\n");
        exit(1);
    }

    // Matches the namespace with or without a leading backslash, but not when it is already prefixed.
    $pattern = '/(?<![\\\\\w])\\\\?' . preg_quote($namespace, '/') . '(?=[\\\\;\s])/';
    $files   = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        $relative = substr($file->getPathname(), \strlen($source) + 1);
        $copy     = "$target/$package/$relative";

        @mkdir(\dirname($copy), 0777, true);

        $contents = file_get_contents($file->getPathname());

        if ($file->getExtension() === 'php') {
            $contents = preg_replace($pattern, $prefix . $namespace, $contents);
        }

        file_put_contents($copy, $contents);
    }

    copy("$root/vendor/$package/LICENSE", "$target/$package/LICENSE");

    echo "Prefixed $package as $prefix$namespace\n";
}

function removeDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($dir);
}
