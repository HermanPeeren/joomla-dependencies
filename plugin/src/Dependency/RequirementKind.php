<?php

/**
 * @package     JoomlaDependencies
 * @license     GNU General Public License version 3 or later
 */

namespace JoomlaDependencies\Plugin\System\Dependencies\Dependency;

\defined('_JEXEC') or die;

enum RequirementKind: string
{
    case Extension    = 'extension';
    case Joomla       = 'joomla';
    case Php          = 'php';
    case PhpExtension = 'php-extension';
}
