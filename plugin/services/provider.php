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
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $plugin = new Dependencies(
                    (array) PluginHelper::getPlugin('system', 'dependencies'),
                    new DependencyService($container->get(DatabaseInterface::class))
                );
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};
