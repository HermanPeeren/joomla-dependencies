<?php

/**
 * @package     JoomlaDependencies
 * @license     GNU General Public License version 3 or later
 */

namespace JoomlaDependencies\Plugin\System\Dependencies\Site;

\defined('_JEXEC') or die;

use Joomla\CMS\Version;
use Joomla\Database\DatabaseInterface;
use JoomlaDependencies\Plugin\System\Dependencies\Dependency\Checker;
use JoomlaDependencies\Plugin\System\Dependencies\Dependency\ExtensionKey;
use JoomlaDependencies\Plugin\System\Dependencies\Dependency\InstalledExtension;
use JoomlaDependencies\Plugin\System\Dependencies\Dependency\Problem;
use JoomlaDependencies\Plugin\System\Dependencies\Dependency\Requirement;
use JoomlaDependencies\Plugin\System\Dependencies\Dependency\SiteState;

/**
 * What the plugin does to a site: keep the dependency table up to date and lock what other extensions need.
 */
final class DependencyService
{
    private readonly ExtensionTable $extensions;

    private readonly DependencyStore $store;

    public function __construct(
        DatabaseInterface $db,
        private readonly InstalledManifests $manifests = new InstalledManifests(),
        private readonly Checker $checker = new Checker()
    ) {
        $this->extensions = new ExtensionTable($db);
        $this->store      = new DependencyStore($db);
    }

    public function extensions(): ExtensionTable
    {
        return $this->extensions;
    }

    public function checker(): Checker
    {
        return $this->checker;
    }

    /**
     * The site as it is now: installed extensions with the requirements recorded for them.
     */
    public function siteState(): SiteState
    {
        $requirements = $this->store->all();
        $installed    = [];

        foreach ($this->extensions->all() as $row) {
            $key = ExtensionTable::keyOf($row);

            if ($key !== null) {
                $installed[] = new InstalledExtension($key, (string) $row->name, ExtensionTable::versionOf($row), $requirements[$key->id()] ?? []);
            }
        }

        $phpExtensions = [];

        foreach (get_loaded_extensions() as $name) {
            $phpExtensions[$name] = (string) phpversion($name);
        }

        return SiteState::create($installed, (new Version())->getShortVersion(), PHP_VERSION, $phpExtensions);
    }

    /**
     * @param  Requirement[]  $requirements
     */
    public function record(ExtensionKey $key, array $requirements): void
    {
        $this->store->replace($key, $requirements);
    }

    public function forget(ExtensionKey $key): void
    {
        $this->store->forget($key);
    }

    /**
     * Rebuilds the dependency table from the manifests of all installed extensions, then the locks.
     *
     * @return string[]  Warnings from manifests that couldn't be read completely
     */
    public function sync(): array
    {
        $warnings = [];

        $this->store->clear();

        foreach ($this->extensions->all() as $row) {
            $key      = ExtensionTable::keyOf($row);
            $manifest = $key !== null ? $this->manifests->read($key) : null;

            if ($manifest === null) {
                continue;
            }

            if ($manifest->requirements !== []) {
                $this->store->replace($key, $manifest->requirements);
            }

            foreach ($manifest->warnings as $warning) {
                $warnings[] = $row->name . ': ' . $warning;
            }
        }

        $this->applyLocks();

        return $warnings;
    }

    /**
     * Locks every installed extension that another installed extension requires, and unlocks what this plugin
     * locked earlier and nothing requires any more. Extensions locked by someone else (Joomla core) are left alone.
     */
    public function applyLocks(): void
    {
        $site   = $this->siteState();
        $needed = [];

        foreach ($site->extensions() as $extension) {
            foreach ($extension->requirements as $requirement) {
                if ($requirement->extension !== null && $site->find($requirement->extension) !== null) {
                    $needed[$requirement->extension->id()] = $requirement->extension;
                }
            }
        }

        $ours = $this->store->locks();

        foreach ($needed as $id => $key) {
            $rows   = $this->extensions->byKey($key);
            $toLock = array_filter($rows, static fn (object $row): bool => (int) $row->locked === 0);

            if ($toLock !== []) {
                $this->extensions->setLocked(array_map(static fn (object $row): int => (int) $row->extension_id, $toLock), true);
                $this->store->addLock($key);
            } elseif (\in_array($id, $ours, true)) {
                // Still ours, still locked: an update re-locked a row we had released.
                $this->store->addLock($key);
            }
        }

        foreach ($ours as $id) {
            if (isset($needed[$id])) {
                continue;
            }

            $this->releaseLock(ExtensionKey::fromId($id));
            $this->store->removeLock($id);
        }
    }

    /**
     * Unlocks the extension for now, if this plugin locked it, so its own update can replace it. The record stays,
     * so applyLocks() locks it again afterwards.
     */
    public function releaseLock(ExtensionKey $key): void
    {
        if (!$this->isOurs($key)) {
            return;
        }

        $this->extensions->setLocked(
            array_map(static fn (object $row): int => (int) $row->extension_id, $this->extensions->byKey($key)),
            false
        );
    }

    public function lockCount(): int
    {
        return \count($this->store->locks());
    }

    /**
     * @return string[]  Key ids of the extensions this plugin locked
     */
    public function locks(): array
    {
        return $this->store->locks();
    }

    public function isOurs(ExtensionKey $key): bool
    {
        return \in_array($key->id(), $this->store->locks(), true);
    }

    /**
     * @return ExtensionKey[]
     */
    public function packageChildren(ExtensionKey $package): array
    {
        return $this->manifests->packageChildren($package);
    }

    /**
     * Before a package is uninstalled: unlock its children that only extensions leaving with it require, so the
     * package can take them along. Children that others still need stay locked, and Joomla keeps them.
     *
     * @return array<string, InstalledExtension[]>  Child key id => the extensions outside the package that keep it
     */
    public function prepareForPackageUninstall(ExtensionKey $package): array
    {
        $children = $this->manifests->packageChildren($package);
        $leaving  = [$package->id() => true];

        foreach ($children as $child) {
            $leaving[$child->id()] = true;
        }

        $kept = [];

        foreach ($children as $child) {
            if (!$this->isOurs($child)) {
                continue;
            }

            $others = $this->dependentsOf($child, array_keys($leaving));

            if ($others === []) {
                $this->releaseLock($child);
            } else {
                $kept[$child->id()] = $others;
            }
        }

        return $kept;
    }

    /**
     * Installed extensions that require $key, apart from those in $except.
     *
     * @param  string[]  $except  Key ids
     *
     * @return InstalledExtension[]
     */
    public function dependentsOf(ExtensionKey $key, array $except = []): array
    {
        $dependents = [];

        foreach ($this->siteState()->dependentsOf($key) as [$extension]) {
            if (!\in_array($extension->key->id(), $except, true)) {
                $dependents[$extension->key->id()] = $extension;
            }
        }

        return array_values($dependents);
    }

    /**
     * After a package is uninstalled, children it left behind still point at it. Give each the latest installed
     * package that lists it, or none.
     */
    public function repairPackageIds(int $removedPackageId): void
    {
        $packages = $this->packagesListing($removedPackageId);

        foreach ($this->extensions->childrenOf($removedPackageId) as $row) {
            $key = ExtensionTable::keyOf($row);

            $this->extensions->setPackageId((int) $row->extension_id, $key !== null ? $this->packageFor($key, $packages) : 0);
        }
    }

    /**
     * A library updated on its own gets a new row with package_id 0; other types keep their row. Give such a
     * library back to the latest installed package that lists it, so that package's blockChildUninstall applies
     * again.
     *
     * @param  ExtensionKey[]  $keys
     */
    public function adoptOrphans(array $keys): void
    {
        $packages = null;

        foreach ($keys as $key) {
            if ($key->type !== 'library') {
                continue;
            }

            foreach ($this->extensions->byKey($key) as $row) {
                if ((int) $row->package_id !== 0) {
                    continue;
                }

                $packages ??= $this->packagesListing();
                $packageId = $this->packageFor($key, $packages);

                if ($packageId !== 0) {
                    $this->extensions->setPackageId((int) $row->extension_id, $packageId);
                }
            }
        }
    }

    /**
     * Installed packages and the children their manifests list, latest installed first.
     *
     * @return array<int, string[]>  Package extension_id => child key ids
     */
    private function packagesListing(int $except = 0): array
    {
        $packages = [];

        foreach ($this->extensions->all() as $row) {
            if ($row->type !== 'package' || (int) $row->extension_id === $except) {
                continue;
            }

            $key = ExtensionTable::keyOf($row);

            if ($key !== null) {
                $packages[(int) $row->extension_id] = array_map(
                    static fn (ExtensionKey $child): string => $child->id(),
                    $this->manifests->packageChildren($key)
                );
            }
        }

        krsort($packages);

        return $packages;
    }

    /**
     * @param  array<int, string[]>  $packages
     */
    private function packageFor(ExtensionKey $key, array $packages): int
    {
        foreach ($packages as $packageId => $children) {
            if (\in_array($key->id(), $children, true)) {
                return $packageId;
            }
        }

        return 0;
    }

    /**
     * Requirements that are unmet now and involve the given extensions: their own, and those of other extensions
     * on them. For routes where the plugin couldn't refuse the install.
     *
     * @param  ExtensionKey[]  $touched
     *
     * @return Problem[]
     */
    public function unmetAround(array $touched): array
    {
        $site     = $this->siteState();
        $ids      = array_map(static fn (ExtensionKey $key): string => $key->id(), $touched);
        $problems = [];

        foreach ($site->extensions() as $extension) {
            $isTouched = \in_array($extension->key->id(), $ids, true);

            foreach ($extension->requirements as $requirement) {
                $onTouched = $requirement->extension !== null && \in_array($requirement->extension->id(), $ids, true);

                if (!$isTouched && !$onTouched) {
                    continue;
                }

                $problem = $this->checker->checkRequirement($extension->name ?: (string) $extension->key, $requirement, $site);

                if ($problem !== null) {
                    $problems[] = $problem;
                }
            }
        }

        return $problems;
    }

    /**
     * Unlocks everything this plugin locked and forgets its locks: for uninstalling the plugin.
     */
    public function unlockAll(): void
    {
        foreach ($this->store->locks() as $id) {
            $this->releaseLock(ExtensionKey::fromId($id));
            $this->store->removeLock($id);
        }
    }
}
