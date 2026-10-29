<?php

/**
 * @package     JoomlaDependencies
 * @license     GNU General Public License version 3 or later
 *
 * Loads the plugin's classes and its prefixed copy of composer/semver.
 *
 * Joomla registers a new extension's namespace for the next request; the install script needs the classes in the
 * same request, so the plugin brings its own loader.
 */

\defined('_JEXEC') or die;

if (!\defined('JOOMLA_DEPENDENCIES_AUTOLOAD')) {
    \define('JOOMLA_DEPENDENCIES_AUTOLOAD', 1);

    spl_autoload_register(static function (string $class): void {
        $map = [
            'JoomlaDependencies\\Plugin\\System\\Dependencies\\' => __DIR__ . '/src/',
            'JoomlaDependencies\\Vendor\\Composer\\Semver\\'     => __DIR__ . '/vendor-prefixed/composer/semver/',
        ];

        foreach ($map as $prefix => $dir) {
            if (str_starts_with($class, $prefix)) {
                $file = $dir . str_replace('\\', '/', substr($class, \strlen($prefix))) . '.php';

                if (is_file($file)) {
                    require $file;
                }

                return;
            }
        }
    });
}
