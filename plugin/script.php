<?php

/**
 * @package     JoomlaDependencies
 * @license     GNU General Public License version 3 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use JoomlaDependencies\Plugin\System\Dependencies\Site\DependencyService;

/**
 * Install: enables the plugin and builds the dependency table from all installed extensions, so a site that already
 * has extensions with <dependencies> is protected at once. Uninstall: unlocks everything the plugin locked, before
 * Joomla drops its tables.
 */
class PlgSystemDependenciesInstallerScript
{
    public function postflight(string $type, InstallerAdapter $adapter): void
    {
        if ($type !== 'install' && $type !== 'update' && $type !== 'discover_install') {
            return;
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);

        if ($type !== 'update') {
            $query = $db->createQuery()
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('enabled') . ' = 1')
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('dependencies'));
            $db->setQuery($query)->execute();
        }

        $service = $this->service($adapter, $db);

        foreach ($service->sync() as $warning) {
            Factory::getApplication()->enqueueMessage($warning, 'warning');
        }

        $this->loadLanguage($adapter);
        Factory::getApplication()->enqueueMessage(Text::sprintf('PLG_SYSTEM_DEPENDENCIES_INSTALLED', $service->lockCount()));
    }

    public function uninstall(InstallerAdapter $adapter): void
    {
        $this->service($adapter, Factory::getContainer()->get(DatabaseInterface::class))->unlockAll();
    }

    private function service(InstallerAdapter $adapter, DatabaseInterface $db): DependencyService
    {
        require_once $this->pluginPath($adapter) . '/autoload.php';

        return new DependencyService($db);
    }

    private function loadLanguage(InstallerAdapter $adapter): void
    {
        Factory::getApplication()->getLanguage()->load('plg_system_dependencies', $this->pluginPath($adapter));
    }

    /**
     * The installed plugin folder; during an install the source folder has the same files.
     */
    private function pluginPath(InstallerAdapter $adapter): string
    {
        $installed = JPATH_PLUGINS . '/system/dependencies';

        return is_file($installed . '/autoload.php') ? $installed : $adapter->getParent()->getPath('source');
    }
}
