<?php

/**
 * @package     JoomlaDependencies
 * @license     GNU General Public License version 3 or later
 */

namespace JoomlaDependencies\Plugin\System\Dependencies\Dependency;

\defined('_JEXEC') or die;

/**
 * An extension as the site has it: installed, enabled or not, with the requirements its manifest declared.
 */
final readonly class InstalledExtension
{
    /**
     * @param  Requirement[]  $requirements
     */
    public function __construct(
        public ExtensionKey $key,
        public string $name,
        public string $version,
        public array $requirements = []
    ) {
    }

    public static function fromManifest(ExtensionManifest $manifest): self
    {
        return new self($manifest->key, $manifest->name, $manifest->version, $manifest->requirements);
    }
}
