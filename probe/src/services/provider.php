<?php

/**
 * @package     JoomlaDependencies
 * @subpackage  Probe
 * @license     GNU General Public License version 3 or later
 *
 * Template: tools/build.php replaces __GROUP__ and __Group__ for each plugin group.
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use JoomlaDependencies\Plugin\__Group__\DepProbe\Extension\DepProbe;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            $container->lazy(DepProbe::class, function (Container $container) {
                return new DepProbe((array) PluginHelper::getPlugin('__GROUP__', 'depprobe'));
            })
        );
    }
};
