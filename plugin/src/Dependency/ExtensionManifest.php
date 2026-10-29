<?php

/**
 * @package     JoomlaDependencies
 * @license     GNU General Public License version 3 or later
 */

namespace JoomlaDependencies\Plugin\System\Dependencies\Dependency;

\defined('_JEXEC') or die;

/**
 * What the prototype needs from an extension's manifest: who it is, its version, what it requires, and for a
 * package the extensions it carries.
 */
final readonly class ExtensionManifest
{
    /**
     * @param  Requirement[]        $requirements
     * @param  ExtensionManifest[]  $children  A package's extensions; empty for other types
     * @param  string[]             $warnings  Problems in the manifest itself, e.g. an unknown dependency type
     */
    public function __construct(
        public ExtensionKey $key,
        public string $name,
        public string $version,
        public array $requirements = [],
        public array $children = [],
        public array $warnings = []
    ) {
    }

    /**
     * This extension and, for a package, everything inside it, nested packages included.
     *
     * @return ExtensionManifest[]
     */
    public function flatten(): array
    {
        $all = [$this];

        foreach ($this->children as $child) {
            array_push($all, ...$child->flatten());
        }

        return $all;
    }
}
