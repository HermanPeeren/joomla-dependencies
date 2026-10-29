<?php

/**
 * @package     JoomlaDependencies
 * @license     GNU General Public License version 3 or later
 */

namespace JoomlaDependencies\Plugin\System\Dependencies\Dependency;

\defined('_JEXEC') or die;

/**
 * What a site has: its extensions, Joomla and PHP versions and PHP extensions. Immutable: withInstalled() returns
 * the site as it will be after an install, so a check can run before anything is written.
 *
 * Disabled extensions count as installed.
 */
final readonly class SiteState
{
    /**
     * @param  array<string, InstalledExtension>  $extensions     Keyed by ExtensionKey::id()
     * @param  array<string, string>              $phpExtensions  Lower-case name => version ('' when unknown)
     */
    private function __construct(
        private array $extensions,
        public string $joomlaVersion,
        public string $phpVersion,
        public array $phpExtensions
    ) {
    }

    /**
     * @param  InstalledExtension[]   $extensions
     * @param  array<string, string>  $phpExtensions  Name => version
     */
    public static function create(
        array $extensions,
        string $joomlaVersion,
        string $phpVersion,
        array $phpExtensions
    ): self {
        $byId = [];

        foreach ($extensions as $extension) {
            $byId[$extension->key->id()] = $extension;
        }

        return new self($byId, $joomlaVersion, $phpVersion, array_change_key_case($phpExtensions, CASE_LOWER));
    }

    public function find(ExtensionKey $key): ?InstalledExtension
    {
        return $this->extensions[$key->id()] ?? null;
    }

    /**
     * @return InstalledExtension[]
     */
    public function extensions(): array
    {
        return array_values($this->extensions);
    }

    /**
     * The site after installing $manifest: the extension and, for a package, everything in it, added or replaced.
     */
    public function withInstalled(ExtensionManifest $manifest): self
    {
        $extensions = $this->extensions;

        foreach ($manifest->flatten() as $item) {
            $extensions[$item->key->id()] = InstalledExtension::fromManifest($item);
        }

        return new self($extensions, $this->joomlaVersion, $this->phpVersion, $this->phpExtensions);
    }

    /**
     * Installed extensions that declare a requirement on $key, with that requirement.
     *
     * @return array<int, array{InstalledExtension, Requirement}>
     */
    public function dependentsOf(ExtensionKey $key): array
    {
        $dependents = [];

        foreach ($this->extensions as $extension) {
            foreach ($extension->requirements as $requirement) {
                if ($requirement->targets($key)) {
                    $dependents[] = [$extension, $requirement];
                }
            }
        }

        return $dependents;
    }
}
