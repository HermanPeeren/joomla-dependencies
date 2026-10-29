<?php

/**
 * Builds the fixture extensions into fixtures/dist/ and the event probe into probe/dist/.
 *
 *   php tools/build.php
 */

declare(strict_types=1);

$root = \dirname(__DIR__);

buildFixtures($root . '/fixtures/fixtures.php', $root . '/fixtures/dist');
buildProbe($root . '/probe', $root . '/probe/dist');

function buildFixtures(string $definitionFile, string $dist): void
{
    $fixtures = require $definitionFile;

    resetDir($dist);

    foreach ($fixtures['libraries'] as $element => $versions) {
        foreach ($versions as $version) {
            writeZip("$dist/lib_$element-$version.zip", [
                "$element.xml"    => libraryManifest($element, $version),
                'src/Version.php' => libraryVersionClass($element, $version),
            ]);
        }
    }

    foreach ($fixtures['components'] as $element => $component) {
        $name = substr($element, 4);

        writeZip("$dist/$element-{$component['version']}.zip", [
            "$name.xml"                => componentManifest($element, $component['version'], $component['dependencies']),
            'administrator/README.txt' => "Fixture component $element. It has no screens.\n",
        ]);
    }

    foreach ($fixtures['packages'] as $zipName => $package) {
        $files = ["pkg_{$package['element']}.xml" => packageManifest($package, $dist)];

        foreach ($package['children'] as $child) {
            $files["$child.zip"] = file_get_contents("$dist/$child.zip");
        }

        writeZip("$dist/$zipName.zip", $files);
    }

    echo 'Fixtures: ' . \count(glob("$dist/*.zip")) . " zips in fixtures/dist/\n";
}

function buildProbe(string $source, string $dist): void
{
    resetDir($dist);

    $children = [];

    foreach (['system', 'installer', 'extension'] as $group) {
        $replace = ['__GROUP__' => $group, '__Group__' => ucfirst($group)];
        $zip     = "plg_{$group}_depprobe.zip";

        writeZip("$dist/$zip", [
            'depprobe.xml'                => pluginManifest($group),
            'services/provider.php'       => strtr(file_get_contents("$source/src/services/provider.php"), $replace),
            'src/Extension/DepProbe.php'  => strtr(file_get_contents("$source/src/Extension/DepProbe.php"), $replace),
        ]);

        $children[$group] = $zip;
    }

    $files = [
        'pkg_depprobe.xml' => probePackageManifest($children),
        'script.php'       => file_get_contents("$source/script.php"),
    ];

    foreach ($children as $zip) {
        $files[$zip] = file_get_contents("$dist/$zip");
    }

    writeZip("$dist/pkg_depprobe.zip", $files);

    echo "Probe: pkg_depprobe.zip in probe/dist/\n";
}

function libraryManifest(string $element, string $version): string
{
    $namespace = 'JoomlaDependencies\\Fixture\\' . ucfirst($element);

    return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <extension type="library" method="upgrade">
            <name>Fixture library $element</name>
            <libraryname>$element</libraryname>
            <version>$version</version>
            <creationDate>2026-10</creationDate>
            <author>Joomla dependencies fixtures</author>
            <license>GPL-3.0-or-later</license>
            <description>Fixture: a shared library, version $version.</description>
            <namespace path="src">$namespace</namespace>
            <files>
                <folder>src</folder>
            </files>
        </extension>

        XML;
}

function libraryVersionClass(string $element, string $version): string
{
    $namespace = 'JoomlaDependencies\\Fixture\\' . ucfirst($element);

    return <<<PHP
        <?php

        namespace $namespace;

        \\defined('_JEXEC') or die;

        final class Version
        {
            public const VERSION = '$version';
        }

        PHP;
}

/**
 * @param  array<int, array<string, string>>  $dependencies
 */
function componentManifest(string $element, string $version, array $dependencies): string
{
    $lines = [];

    foreach ($dependencies as $dependency) {
        $attributes = '';

        foreach ($dependency as $name => $value) {
            $attributes .= ' ' . $name . '="' . htmlspecialchars($value, ENT_XML1 | ENT_QUOTES) . '"';
        }

        $lines[] = "        <dependency$attributes />";
    }

    $dependencyXml = implode("\n", $lines);
    $description   = htmlspecialchars(
        'Fixture: needs ' . implode(', ', array_map(
            static fn (array $d): string => trim($d['element'] . ' ' . ($d['version'] ?? '')),
            $dependencies
        )) . '.',
        ENT_XML1
    );

    return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <extension type="component" method="upgrade">
            <name>$element</name>
            <version>$version</version>
            <creationDate>2026-10</creationDate>
            <author>Joomla dependencies fixtures</author>
            <license>GPL-3.0-or-later</license>
            <description>$description</description>
            <dependencies>
        $dependencyXml
            </dependencies>
            <administration>
                <files folder="administrator">
                    <filename>README.txt</filename>
                </files>
            </administration>
        </extension>

        XML;
}

/**
 * @param  array{element: string, version: string, children: string[]}  $package
 */
function packageManifest(array $package, string $dist): string
{
    $lines = [];

    foreach ($package['children'] as $child) {
        $manifest = childManifest("$dist/$child.zip");
        $type     = (string) $manifest['type'];
        $id       = $type === 'library' ? (string) $manifest->libraryname : (string) $manifest->name;

        $lines[] = "        <file type=\"$type\" id=\"$id\">$child.zip</file>";
    }

    $files = implode("\n", $lines);

    return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <extension type="package" method="upgrade">
            <name>Fixture package {$package['element']}</name>
            <packagename>{$package['element']}</packagename>
            <version>{$package['version']}</version>
            <creationDate>2026-10</creationDate>
            <author>Joomla dependencies fixtures</author>
            <license>GPL-3.0-or-later</license>
            <description>Fixture package: {$package['element']}.</description>
            <files>
        $files
            </files>
        </extension>

        XML;
}

function pluginManifest(string $group): string
{
    $namespace = 'JoomlaDependencies\\Plugin\\' . ucfirst($group) . '\\DepProbe';

    return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <extension type="plugin" group="$group" method="upgrade">
            <name>plg_{$group}_depprobe</name>
            <version>0.1.0</version>
            <creationDate>2026-10</creationDate>
            <author>Joomla dependencies</author>
            <license>GPL-3.0-or-later</license>
            <description>Logs which installer events reach a $group plugin, to administrator/logs/depprobe.log.</description>
            <namespace path="src">$namespace</namespace>
            <files>
                <folder plugin="depprobe">services</folder>
                <folder>src</folder>
            </files>
        </extension>

        XML;
}

/**
 * @param  array<string, string>  $children  group => zip name
 */
function probePackageManifest(array $children): string
{
    $lines = [];

    foreach ($children as $group => $zip) {
        $lines[] = "        <file type=\"plugin\" id=\"depprobe\" group=\"$group\">$zip</file>";
    }

    $files = implode("\n", $lines);

    return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <extension type="package" method="upgrade">
            <name>Dependency event probe</name>
            <packagename>depprobe</packagename>
            <version>0.1.0</version>
            <creationDate>2026-10</creationDate>
            <author>Joomla dependencies</author>
            <license>GPL-3.0-or-later</license>
            <description>Three plugins that log which installer events reach the system, installer and extension groups.</description>
            <scriptfile>script.php</scriptfile>
            <files>
        $files
            </files>
        </extension>

        XML;
}

function childManifest(string $zipFile): SimpleXMLElement
{
    $zip = new ZipArchive();
    $zip->open($zipFile);

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);

        if (!str_contains($name, '/') && str_ends_with($name, '.xml')) {
            $xml = simplexml_load_string($zip->getFromIndex($i));

            if ($xml !== false && $xml->getName() === 'extension') {
                $zip->close();

                return $xml;
            }
        }
    }

    throw new RuntimeException("No manifest in $zipFile");
}

/**
 * @param  array<string, string>  $files  path in zip => contents
 */
function writeZip(string $file, array $files): void
{
    $zip = new ZipArchive();

    if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException("Cannot write $file");
    }

    foreach ($files as $path => $contents) {
        $zip->addFromString($path, $contents);
    }

    $zip->close();
}

function resetDir(string $dir): void
{
    if (is_dir($dir)) {
        array_map('unlink', glob("$dir/*"));
    } else {
        mkdir($dir, 0777, true);
    }
}
