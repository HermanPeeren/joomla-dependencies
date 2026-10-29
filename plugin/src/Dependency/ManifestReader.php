<?php

/**
 * @package     JoomlaDependencies
 * @license     GNU General Public License version 3 or later
 */

namespace JoomlaDependencies\Plugin\System\Dependencies\Dependency;

\defined('_JEXEC') or die;

/**
 * Reads extension manifests: from a folder (an unpacked install package), a zip (a package's child) or XML.
 *
 * Derives the element the same way Joomla's installer adapters do (Joomla 6.2), so the key matches the row the
 * installer will write to #__extensions.
 */
final class ManifestReader
{
    /**
     * Reads the manifest in an unpacked extension folder, the way Installer::findManifest() looks for it: XML files
     * in the folder itself first, then one level down.
     *
     * @throws \RuntimeException  When the folder has no extension manifest
     */
    public function fromDirectory(string $dir): ExtensionManifest
    {
        $dir   = rtrim($dir, '/\\');
        $files = array_unique(array_merge(
            glob($dir . '/*.xml') ?: [],
            glob($dir . '/*/*.xml') ?: []
        ));

        foreach ($files as $file) {
            $xml = $this->load((string) file_get_contents($file));

            if ($xml !== null) {
                return $this->fromXml($xml, basename($file), $this->childLoaderForDirectory(\dirname($file)));
            }
        }

        throw new \RuntimeException(\sprintf('No extension manifest in %s', $dir));
    }

    /**
     * Reads the manifest inside an extension zip, without unpacking it.
     *
     * @throws \RuntimeException  When the zip can't be read or has no extension manifest
     */
    public function fromZip(string $zipFile): ExtensionManifest
    {
        return $this->fromZipContents((string) @file_get_contents($zipFile), basename($zipFile));
    }

    /**
     * @param  callable(string $path): ExtensionManifest|null  $loadChild
     *         Reads a package child by its path relative to the package manifest; null to skip children
     *
     * @throws \RuntimeException  When the manifest doesn't identify an extension
     */
    public function fromXml(\SimpleXMLElement $xml, string $fileName = '', ?callable $loadChild = null): ExtensionManifest
    {
        $warnings = [];

        try {
            $key = $this->key($xml, $fileName);
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException(\sprintf('Manifest %s: %s', $fileName ?: trim((string) $xml->name), $e->getMessage()), 0, $e);
        }

        [$requirements, $requirementWarnings] = $this->requirements($xml);
        array_push($warnings, ...$requirementWarnings);

        $children = [];

        if ($key->type === 'package' && $loadChild !== null) {
            [$children, $childWarnings] = $this->children($xml, $loadChild);
            array_push($warnings, ...$childWarnings);
        }

        return new ExtensionManifest(
            $key,
            trim((string) $xml->name),
            trim((string) $xml->version),
            $requirements,
            $children,
            $warnings
        );
    }

    private function fromZipContents(string $contents, string $label): ExtensionManifest
    {
        // ZipArchive needs a file; keep it only as long as needed.
        $tmp = tempnam(sys_get_temp_dir(), 'jdep');
        file_put_contents($tmp, $contents);

        try {
            $zip = new \ZipArchive();

            if ($contents === '' || $zip->open($tmp) !== true) {
                throw new \RuntimeException(\sprintf('Cannot read zip %s', $label));
            }

            try {
                return $this->fromOpenZip($zip, $label);
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($tmp);
        }
    }

    private function fromOpenZip(\ZipArchive $zip, string $label): ExtensionManifest
    {
        $topLevel = [];
        $oneDown  = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            if (!str_ends_with(strtolower($name), '.xml')) {
                continue;
            }

            match (substr_count($name, '/')) {
                0       => $topLevel[] = $name,
                1       => $oneDown[]  = $name,
                default => null,
            };
        }

        foreach ([...$topLevel, ...$oneDown] as $name) {
            $xml = $this->load((string) $zip->getFromName($name));

            if ($xml === null) {
                continue;
            }

            $base      = \dirname($name) === '.' ? '' : \dirname($name) . '/';
            $loadChild = function (string $path) use ($zip, $base, $label): ExtensionManifest {
                $entry = $base . $path;

                if ($zip->locateName($entry) !== false) {
                    return $this->fromZipContents((string) $zip->getFromName($entry), basename($entry));
                }

                // A child unpacked as a folder inside the package zip.
                throw new \RuntimeException(\sprintf('Package child %s not found as a zip in %s', $path, $label));
            };

            return $this->fromXml($xml, basename($name), $loadChild);
        }

        throw new \RuntimeException(\sprintf('No extension manifest in %s', $label));
    }

    private function childLoaderForDirectory(string $dir): callable
    {
        return function (string $path) use ($dir): ExtensionManifest {
            $full = $dir . '/' . $path;

            if (is_dir($full)) {
                return $this->fromDirectory($full);
            }

            if (is_file($full)) {
                return $this->fromZip($full);
            }

            throw new \RuntimeException(\sprintf('Package child %s not found', $path));
        };
    }

    /**
     * The element as each installer adapter derives it.
     */
    private function key(\SimpleXMLElement $xml, string $fileName): ExtensionKey
    {
        $type   = strtolower((string) $xml['type']);
        $client = (string) $xml['client'];

        return match ($type) {
            // ComponentAdapter: <element> or <name>, cmd-filtered, lower case, with com_ in front.
            'component' => ExtensionKey::of($type, $this->componentElement($xml)),
            // LibraryAdapter: <libraryname> as it is.
            'library'   => ExtensionKey::of($type, trim((string) $xml->libraryname)),
            // PluginAdapter: the <files> child with a plugin="" attribute; the group is the folder.
            'plugin'    => ExtensionKey::of($type, $this->filesAttribute($xml, 'plugin'), (string) $xml['group']),
            // ModuleAdapter: <element>, else the <files> child with a module="" attribute, lower case.
            'module'    => ExtensionKey::of($type, trim((string) $xml->element) ?: strtolower($this->filesAttribute($xml, 'module')), '', $client),
            // PackageAdapter: pkg_ + <packagename>, cmd-filtered.
            'package'   => ExtensionKey::of($type, 'pkg_' . self::cmd((string) $xml->packagename)),
            // FileAdapter: the manifest's file name without .xml.
            'file'      => ExtensionKey::of($type, preg_replace('/\.xml$/i', '', $fileName)),
            // LanguageAdapter: the language tag.
            'language'  => ExtensionKey::of($type, trim((string) $xml->tag) ?: self::baseElement($xml), '', $client),
            // TemplateAdapter: the generic rule.
            'template'  => ExtensionKey::of($type, self::baseElement($xml), '', $client),
            default     => throw new \RuntimeException(\sprintf('Unknown extension type "%s"', $type)),
        };
    }

    private function componentElement(\SimpleXMLElement $xml): string
    {
        $element = self::baseElement($xml);

        return str_starts_with($element, 'com_') ? $element : 'com_' . $element;
    }

    /**
     * InstallerAdapter::getElement(): <element>, else <name>, cmd-filtered and lower case.
     */
    private static function baseElement(\SimpleXMLElement $xml): string
    {
        $element = trim((string) $xml->element) ?: trim((string) $xml->name);

        return strtolower(self::cmd($element));
    }

    /**
     * InputFilter's 'cmd': letters, digits, underscores, dots and hyphens; no leading dot.
     */
    private static function cmd(string $value): string
    {
        return ltrim((string) preg_replace('/[^A-Z0-9_.-]/i', '', $value), '.');
    }

    private function filesAttribute(\SimpleXMLElement $xml, string $attribute): string
    {
        foreach ($xml->files->children() ?? [] as $file) {
            if ((string) $file[$attribute] !== '') {
                return (string) $file[$attribute];
            }
        }

        return '';
    }

    /**
     * @return array{Requirement[], string[]}
     */
    private function requirements(\SimpleXMLElement $xml): array
    {
        $requirements = [];
        $warnings     = [];

        foreach ($xml->dependencies->dependency ?? [] as $dependency) {
            $type       = strtolower(trim((string) $dependency['type']));
            $element    = trim((string) $dependency['element']);
            $constraint = trim((string) $dependency['version']);

            try {
                $requirements[] = match ($type) {
                    'joomla'        => Requirement::joomla($constraint),
                    'php'           => Requirement::php($constraint),
                    'php-extension' => $element !== ''
                        ? Requirement::phpExtension($element, $constraint)
                        : throw new \InvalidArgumentException('A php-extension dependency needs an element'),
                    default         => Requirement::extension(
                        ExtensionKey::of($type, $element, (string) $dependency['folder'], (string) $dependency['client']),
                        $constraint
                    ),
                };
            } catch (\InvalidArgumentException $e) {
                $warnings[] = \sprintf('Ignored dependency "%s": %s', trim($dependency->asXML() ?: ''), $e->getMessage());
            }
        }

        return [$requirements, $warnings];
    }

    /**
     * @return array{ExtensionManifest[], string[]}
     */
    private function children(\SimpleXMLElement $xml, callable $loadChild): array
    {
        $children = [];
        $warnings = [];
        $folder   = trim((string) $xml->files['folder'], '/');

        foreach ($xml->files->file ?? [] as $file) {
            $path = ($folder !== '' ? $folder . '/' : '') . trim((string) $file);

            try {
                $children[] = $loadChild($path);
            } catch (\RuntimeException $e) {
                $warnings[] = $e->getMessage();
            }
        }

        return [$children, $warnings];
    }

    private function load(string $contents): ?\SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        $xml      = simplexml_load_string($contents);
        libxml_use_internal_errors($previous);

        return $xml !== false && $xml->getName() === 'extension' ? $xml : null;
    }
}
