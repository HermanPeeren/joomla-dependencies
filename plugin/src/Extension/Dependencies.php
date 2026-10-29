<?php

/**
 * @package     JoomlaDependencies
 * @license     GNU General Public License version 3 or later
 */

namespace JoomlaDependencies\Plugin\System\Dependencies\Extension;

\defined('_JEXEC') or die;

use Joomla\Application\ApplicationEvents;
use Joomla\Application\Event\ApplicationEvent;
use Joomla\CMS\Application\ConsoleApplication;
use Joomla\CMS\Event\Extension\AfterInstallEvent;
use Joomla\CMS\Event\Extension\AfterUninstallEvent;
use Joomla\CMS\Event\Extension\AfterUpdateEvent;
use Joomla\CMS\Event\Extension\BeforeInstallEvent;
use Joomla\CMS\Event\Extension\BeforeUninstallEvent;
use Joomla\CMS\Event\Extension\BeforeUpdateEvent;
use Joomla\CMS\Event\Installer\AddInstallationTabEvent;
use Joomla\CMS\Event\Installer\BeforeInstallerEvent;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Event\Priority;
use Joomla\Event\SubscriberInterface;
use JoomlaDependencies\Plugin\System\Dependencies\Console\StatusCommand;
use JoomlaDependencies\Plugin\System\Dependencies\Console\SyncCommand;
use JoomlaDependencies\Plugin\System\Dependencies\Console\UnlockAllCommand;
use JoomlaDependencies\Plugin\System\Dependencies\Dependency\ExtensionKey;
use JoomlaDependencies\Plugin\System\Dependencies\Dependency\InstalledExtension;
use JoomlaDependencies\Plugin\System\Dependencies\Dependency\ManifestReader;
use JoomlaDependencies\Plugin\System\Dependencies\Site\DependencyService;
use JoomlaDependencies\Plugin\System\Dependencies\Site\ExtensionTable;
use JoomlaDependencies\Plugin\System\Dependencies\Site\Overview;

/**
 * Dependency management for extensions, from installer events only.
 *
 * - Install screen (onInstallerBeforeInstaller): refuses an install whose dependencies aren't met.
 * - Every install and update: records the extension's <dependencies> and locks what others need. Where the
 *   install couldn't be refused (CLI, update view, discover), warns about unmet dependencies afterwards.
 * - Library updates: unlocks the library first; Joomla replaces a library by uninstalling it.
 * - Uninstall: Joomla refuses locked extensions; the plugin says why, and lets a package take children along
 *   that only it needed.
 *
 * Installer operations nest (a package installs its children; a library update uninstalls the old library), so
 * the work that needs the final state runs when the outermost operation ends.
 */
final class Dependencies extends CMSPlugin implements SubscriberInterface
{
    private const SELF = 'plugin:dependencies:system:';

    protected $autoloadLanguage = true;

    /** Nesting depth of installer operations. */
    private int $depth = 0;

    /** @var array<string, ExtensionKey>  Extensions installed or updated in the current operation */
    private array $touched = [];

    /** Whether the install screen already checked the current operation. */
    private bool $checkedBefore = false;

    /** @var array<string, true>  Libraries being replaced by an update; their inner uninstall is not a real one */
    private array $replacing = [];

    /** @var array<int, array{ExtensionKey, string}>  Uninstalling extension_id => key and type */
    private array $uninstalling = [];

    /** @var array<string, true>  Key ids leaving in the current uninstall, a package's children included */
    private array $leaving = [];

    /** Set when the plugin itself is being uninstalled; from then on it does nothing. */
    private bool $selfUninstalling = false;

    public function __construct(
        array $config,
        private readonly DependencyService $service,
        private readonly ManifestReader $reader = new ManifestReader()
    ) {
        parent::__construct($config);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'onInstallerBeforeInstaller'      => 'onInstallerBeforeInstaller',
            'onExtensionBeforeInstall'        => 'onExtensionBeforeInstall',
            'onExtensionBeforeUpdate'         => 'onExtensionBeforeUpdate',
            'onExtensionAfterInstall'         => 'onExtensionAfterInstall',
            'onExtensionAfterUpdate'          => 'onExtensionAfterUpdate',
            'onExtensionBeforeUninstall'      => 'onExtensionBeforeUninstall',
            'onExtensionAfterUninstall'       => 'onExtensionAfterUninstall',
            // Last, so the upload tab stays the first and default one.
            'onInstallerAddInstallationTab'   => ['onInstallerAddInstallationTab', Priority::MIN],
            ApplicationEvents::BEFORE_EXECUTE => 'registerCommands',
        ];
    }

    /**
     * A "Dependencies" tab on System > Install > Extensions: what is declared, what is unmet, what is locked.
     */
    public function onInstallerAddInstallationTab(AddInstallationTabEvent $event): void
    {
        $overview = Overview::of($this->service);

        ob_start();
        include PluginHelper::getLayoutPath('system', 'dependencies');
        $content = (string) ob_get_clean();

        $label = Text::_('PLG_SYSTEM_DEPENDENCIES_TAB');

        if ($overview->unmetCount() > 0) {
            $label .= ' (' . Text::plural('PLG_SYSTEM_DEPENDENCIES_TAB_UNMET', $overview->unmetCount()) . ')';
        }

        $event->addResult(['name' => 'dependencies', 'label' => $label, 'content' => $content]);
    }

    /**
     * dependencies:status, dependencies:sync and dependencies:unlock-all.
     */
    public function registerCommands(ApplicationEvent $event): void
    {
        $application = $event->getApplication();

        if (!$application instanceof ConsoleApplication) {
            return;
        }

        $application->addCommand(new StatusCommand($this->service));
        $application->addCommand(new SyncCommand($this->service));
        $application->addCommand(new UnlockAllCommand($this->service));
    }

    /**
     * The install screen: the one place a plugin can refuse an install.
     */
    public function onInstallerBeforeInstaller(BeforeInstallerEvent $event): void
    {
        $package = $event->getPackage();

        if (!\is_array($package) || empty($package['dir']) || !is_dir($package['dir'])) {
            return;
        }

        try {
            $incoming = $this->reader->fromDirectory($package['dir']);
        } catch (\RuntimeException) {
            // Not an extension the prototype can read; Joomla will say what's wrong with it.
            return;
        }

        foreach ($incoming->warnings as $warning) {
            $this->getApplication()->enqueueMessage(Text::sprintf('PLG_SYSTEM_DEPENDENCIES_MANIFEST_WARNING', $incoming->name, $warning), 'warning');
        }

        $problems = $this->service->checker()->checkInstall($incoming, $this->service->siteState());

        if ($problems === []) {
            $this->checkedBefore = true;

            return;
        }

        $this->getApplication()->enqueueMessage(Text::sprintf('PLG_SYSTEM_DEPENDENCIES_REFUSED', $incoming->name ?: (string) $incoming->key), 'error');

        foreach ($problems as $problem) {
            $this->getApplication()->enqueueMessage(ProblemText::of($problem), 'error');
        }

        $event->addResult(false);
    }

    public function onExtensionBeforeInstall(BeforeInstallEvent $event): void
    {
        $this->beforeInstallOrUpdate($event->getArgument('manifest'));
    }

    public function onExtensionBeforeUpdate(BeforeUpdateEvent $event): void
    {
        $this->beforeInstallOrUpdate($event->getArgument('manifest'));
    }

    public function onExtensionAfterInstall(AfterInstallEvent $event): void
    {
        $this->afterInstallOrUpdate($event->getArgument('installer'), $event->getArgument('eid'));
    }

    public function onExtensionAfterUpdate(AfterUpdateEvent $event): void
    {
        $this->afterInstallOrUpdate($event->getArgument('installer'), $event->getArgument('eid'));
    }

    public function onExtensionBeforeUninstall(BeforeUninstallEvent $event): void
    {
        $this->depth++;

        $row = $this->service->extensions()->byId((int) $event->getArgument('eid'));
        $key = $row !== null ? ExtensionTable::keyOf($row) : null;

        if ($key?->id() === self::SELF) {
            // The plugin's own uninstall drops its tables before onExtensionAfterUninstall.
            $this->selfUninstalling = true;
        }

        if ($key === null || $this->selfUninstalling) {
            return;
        }

        $this->uninstalling[(int) $row->extension_id] = [$key, $row->type];

        if (isset($this->replacing[$key->id()])) {
            return;
        }

        $this->leaving[$key->id()] = true;

        if ($key->type === 'package') {
            foreach ($this->service->packageChildren($key) as $child) {
                $this->leaving[$child->id()] = true;
            }

            // Children kept for other extensions get their message when Joomla reaches them.
            $this->service->prepareForPackageUninstall($key);

            return;
        }

        if ((int) $row->locked === 1 && $this->service->isOurs($key)) {
            $dependents = $this->service->dependentsOf($key, array_keys($this->leaving));

            if ($dependents !== []) {
                $this->getApplication()->enqueueMessage(
                    Text::sprintf('PLG_SYSTEM_DEPENDENCIES_KEPT', $row->name, $this->names($dependents)),
                    'warning'
                );
            }
        }
    }

    public function onExtensionAfterUninstall(AfterUninstallEvent $event): void
    {
        $this->depth = max(0, $this->depth - 1);

        if ($this->selfUninstalling) {
            return;
        }

        $eid = (int) $event->getArgument('eid');

        if (isset($this->uninstalling[$eid])) {
            [$key, $type] = $this->uninstalling[$eid];

            if ($event->getArgument('removed') && !isset($this->replacing[$key->id()])) {
                $this->service->forget($key);

                if ($type === 'package') {
                    $this->service->repairPackageIds($eid);
                }
            }
        }

        $this->endOfOperation();
    }

    /**
     * @param  \SimpleXMLElement|null  $manifest  Null for a discover install
     */
    private function beforeInstallOrUpdate(mixed $manifest): void
    {
        $this->depth++;

        if (!$manifest instanceof \SimpleXMLElement || strtolower((string) $manifest['type']) !== 'library') {
            return;
        }

        try {
            $key = $this->reader->fromXml($manifest)->key;
        } catch (\RuntimeException) {
            return;
        }

        if ($this->service->extensions()->byKey($key) !== []) {
            // LibraryAdapter replaces an installed library by uninstalling it first, which a lock would refuse.
            $this->replacing[$key->id()] = true;
            $this->service->releaseLock($key);
        }
    }

    private function afterInstallOrUpdate(mixed $installer, mixed $eid): void
    {
        $this->depth = max(0, $this->depth - 1);

        $row = $eid ? $this->service->extensions()->byId((int) $eid) : null;
        $key = $row !== null ? ExtensionTable::keyOf($row) : null;

        if ($key !== null && $key->id() !== self::SELF) {
            // Not getManifest(): it searches the source path again and can throw.
            $manifest = $installer->manifest ?? null;
            $path     = (string) $installer->getPath('manifest', '');

            if ($manifest instanceof \SimpleXMLElement) {
                try {
                    $this->service->record($key, $this->reader->fromXml($manifest, basename($path))->requirements);
                } catch (\RuntimeException) {
                    // Keep what was recorded before.
                }
            }

            $this->touched[$key->id()] = $key;
            unset($this->replacing[$key->id()]);
        }

        $this->endOfOperation();
    }

    private function endOfOperation(): void
    {
        if ($this->depth > 0) {
            return;
        }

        $this->service->applyLocks();
        $this->service->adoptOrphans(array_values($this->touched));

        if (!$this->checkedBefore && $this->touched !== []) {
            foreach ($this->service->unmetAround(array_values($this->touched)) as $problem) {
                $this->getApplication()->enqueueMessage(Text::sprintf('PLG_SYSTEM_DEPENDENCIES_UNMET_AFTER', ProblemText::of($problem)), 'warning');
            }
        }

        $this->touched       = [];
        $this->checkedBefore = false;
        $this->replacing     = [];
        $this->uninstalling  = [];
        $this->leaving       = [];
    }

    /**
     * @param  InstalledExtension[]  $extensions
     */
    private function names(array $extensions): string
    {
        return implode(', ', array_map(static fn (InstalledExtension $extension): string => $extension->name ?: (string) $extension->key, $extensions));
    }
}
