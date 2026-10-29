<?php

/**
 * @package     JoomlaDependencies
 * @license     GNU General Public License version 3 or later
 */

namespace JoomlaDependencies\Plugin\System\Dependencies\Site;

\defined('_JEXEC') or die;

use JoomlaDependencies\Plugin\System\Dependencies\Dependency\ExtensionKey;
use JoomlaDependencies\Plugin\System\Dependencies\Dependency\ExtensionManifest;
use JoomlaDependencies\Plugin\System\Dependencies\Dependency\ManifestReader;

/**
 * Finds the manifests of installed extensions, where each installer adapter's refreshManifestCache() looks for them.
 */
final class InstalledManifests
{
    public function __construct(private readonly ManifestReader $reader = new ManifestReader())
    {
    }

    public function path(ExtensionKey $key): ?string
    {
        $client = match ($key->clientId) {
            1       => JPATH_ADMINISTRATOR,
            3       => JPATH_API,
            default => JPATH_SITE,
        };

        $candidates = match ($key->type) {
            'component' => [JPATH_ADMINISTRATOR . '/components/' . $key->element . '/' . str_replace('com_', '', $key->element) . '.xml'],
            'module'    => [$client . '/modules/' . $key->element . '/' . $key->element . '.xml'],
            'plugin'    => [JPATH_PLUGINS . '/' . $key->folder . '/' . $key->element . '/' . $key->element . '.xml'],
            'template'  => [$client . '/templates/' . $key->element . '/templateDetails.xml'],
            'language'  => [
                $client . '/language/' . $key->element . '/langmetadata.xml',
                $client . '/language/' . $key->element . '/' . $key->element . '.xml',
            ],
            'file'      => [JPATH_MANIFESTS . '/files/' . $key->element . '.xml'],
            'package'   => [JPATH_MANIFESTS . '/packages/' . $key->element . '.xml'],
            'library'   => [JPATH_MANIFESTS . '/libraries/' . $key->element . '.xml'],
        };

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The installed extension's manifest, without a package's children; null when it can't be found or read.
     */
    public function read(ExtensionKey $key): ?ExtensionManifest
    {
        $path = $this->path($key);

        if ($path === null) {
            return null;
        }

        $xml = $this->load($path);

        if ($xml === null) {
            return null;
        }

        try {
            return $this->reader->fromXml($xml, basename($path));
        } catch (\RuntimeException) {
            return null;
        }
    }

    /**
     * The extensions an installed package's manifest lists, as keys.
     *
     * @return ExtensionKey[]
     */
    public function packageChildren(ExtensionKey $package): array
    {
        $path = $this->path($package);
        $xml  = $path !== null ? $this->load($path) : null;
        $keys = [];

        foreach ($xml?->files->file ?? [] as $file) {
            try {
                $keys[] = ExtensionKey::of((string) $file['type'], (string) $file['id'], (string) $file['group'], (string) $file['client']);
            } catch (\InvalidArgumentException) {
                // A child Joomla itself couldn't uninstall either.
            }
        }

        return $keys;
    }

    private function load(string $path): ?\SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        $xml      = simplexml_load_file($path);
        libxml_use_internal_errors($previous);

        return $xml !== false && $xml->getName() === 'extension' ? $xml : null;
    }
}
