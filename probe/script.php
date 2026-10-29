<?php

/**
 * @package     JoomlaDependencies
 * @subpackage  Probe
 * @license     GNU General Public License version 3 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;

/**
 * Enables the three probe plugins after install, so the probe logs from the next request on.
 */
class Pkg_DepprobeInstallerScript
{
    public function postflight(string $type, $parent): void
    {
        if ($type !== 'install' && $type !== 'discover_install') {
            return;
        }

        $db    = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->createQuery()
            ->update($db->quoteName('#__extensions'))
            ->set($db->quoteName('enabled') . ' = 1')
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('depprobe'));

        $db->setQuery($query)->execute();
    }
}
