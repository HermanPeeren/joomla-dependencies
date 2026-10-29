<?php

/**
 * @package     JoomlaDependencies
 * @subpackage  Probe
 * @license     GNU General Public License version 3 or later
 *
 * Template: tools/build.php replaces __GROUP__ and __Group__ for each plugin group.
 */

namespace JoomlaDependencies\Plugin\__Group__\DepProbe\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\Installer;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;

/**
 * Logs which installer events reach a plugin in the __GROUP__ group, and with what.
 *
 * Writes one line per event to administrator/logs/depprobe.log. It never changes an event's result.
 */
final class DepProbe extends CMSPlugin implements SubscriberInterface
{
    private const EVENTS = [
        'onInstallerBeforeInstallation',
        'onInstallerBeforeInstaller',
        'onInstallerAfterInstaller',
        'onInstallerBeforePackageDownload',
        'onExtensionBeforeInstall',
        'onExtensionAfterInstall',
        'onExtensionBeforeUpdate',
        'onExtensionAfterUpdate',
        'onExtensionBeforeUninstall',
        'onExtensionAfterUninstall',
    ];

    public static function getSubscribedEvents(): array
    {
        return array_fill_keys(self::EVENTS, 'logEvent');
    }

    public function logEvent(Event $event): void
    {
        $arguments = [];

        foreach ($event->getArguments() as $name => $value) {
            $arguments[] = $name . '=' . $this->describe($value);
        }

        $line = \sprintf(
            "%s\t%s\t%s\t%s\t%s\n",
            date('H:i:s'),
            Factory::getApplication()->getName(),
            '__GROUP__',
            $event->getName(),
            implode(' ', $arguments)
        );

        file_put_contents(JPATH_ADMINISTRATOR . '/logs/depprobe.log', $line, FILE_APPEND | LOCK_EX);
    }

    private function describe(mixed $value): string
    {
        if ($value instanceof \SimpleXMLElement) {
            return \sprintf('manifest(%s %s %s)', $value['type'], $value->name, $value->version);
        }

        if ($value instanceof Installer) {
            // Not getManifest(): after a refused uninstall it searches an empty path and throws.
            $manifest = $value->manifest;

            return \sprintf(
                'installer(%s packageUninstall=%s)',
                $manifest ? $manifest->name : '-',
                $value->isPackageUninstall() ? 'yes' : 'no'
            );
        }

        if (\is_array($value)) {
            $parts = [];

            foreach (['type', 'dir'] as $key) {
                if (isset($value[$key]) && \is_string($value[$key])) {
                    $parts[] = $key . ':' . basename($value[$key]);
                }
            }

            return 'array(' . implode(',', $parts ?: array_keys($value)) . ')';
        }

        if (\is_object($value)) {
            return 'object(' . (new \ReflectionClass($value))->getShortName() . ')';
        }

        return var_export($value, true);
    }
}
