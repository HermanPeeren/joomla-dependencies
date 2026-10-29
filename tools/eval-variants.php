<?php

/**
 * Makes two variants of an extension zip that carries its library and installs it from its own script (the
 * pattern of Exten-gen and Gen-gen), with a <dependencies> element added to its manifest:
 *
 *   <name>-declared.zip  <dependency type="library" element="..." version="^x.y.z" />
 *   <name>-bundled.zip   the same, plus bundled="library/lib_....zip"
 *
 * The originals are not changed; the variants go to eval/dist/.
 *
 *   php tools/eval-variants.php path/to/com_extengen-1.6.6.zip yepr_gen ^0.19.1
 */

declare(strict_types=1);

[, $source, $library, $constraint] = $argv + [null, null, null, null];

if (!$source || !$library || !$constraint || !is_file($source)) {
    fwrite(STDERR, "Usage: php tools/eval-variants.php <extension zip> <library element> <constraint>\n");
    exit(1);
}

$dist = \dirname(__DIR__) . '/eval/dist';
@mkdir($dist, 0777, true);

$zip = new ZipArchive();
$zip->open($source);

$manifestName = null;
$bundledPath  = null;

for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = (string) $zip->getNameIndex($i);

    if (!str_contains($name, '/') && str_ends_with($name, '.xml') && str_contains((string) $zip->getFromIndex($i), '<extension')) {
        $manifestName = $name;
    }

    if (preg_match('#(^|/)lib_' . preg_quote($library, '#') . '[^/]*\.zip$#', $name)) {
        $bundledPath = $name;
    }
}

$manifest = (string) $zip->getFromName((string) $manifestName);
$zip->close();

if ($manifestName === null || $bundledPath === null) {
    fwrite(STDERR, "No manifest or no bundled lib_$library zip in $source\n");
    exit(1);
}

$base = basename($source, '.zip');

foreach (['declared' => '', 'bundled' => " bundled=\"$bundledPath\""] as $variant => $attribute) {
    $dependencies = "    <dependencies>\n"
        . "        <dependency type=\"library\" element=\"$library\" version=\"" . htmlspecialchars($constraint, ENT_XML1 | ENT_QUOTES) . "\"$attribute />\n"
        . "    </dependencies>\n";

    $changed = preg_replace('#(</version>\s*\n)#', "$1$dependencies", $manifest, 1);
    $target  = "$dist/$base-$variant.zip";

    copy($source, $target);

    $copy = new ZipArchive();
    $copy->open($target);
    $copy->addFromString($manifestName, $changed);
    $copy->close();

    echo "$target\n";
}
