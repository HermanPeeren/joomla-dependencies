<?php

/**
 * @package     JoomlaDependencies
 * @license     GNU General Public License version 3 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use JoomlaDependencies\Plugin\System\Dependencies\Extension\Dependencies;
use JoomlaDependencies\Plugin\System\Dependencies\Site\DependencyService;

require_once __DIR__ . '/../autoload.php';

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        // Lazy, as Joomla 6.1+ core plugins are: on PHP 8.4+ the plugin is only built when one of its events fires,
        // so a request without installer activity doesn't construct it or its service.
        $container->set(
            PluginInterface::class,
            $container->lazy(Dependencies::class, function (Container $container) {
                $plugin = new Dependencies(
                    (array) PluginHelper::getPlugin('system', 'dependencies'),
                    new DependencyService($container->get(DatabaseInterface::class))
                );
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            })
        );
    }
};
