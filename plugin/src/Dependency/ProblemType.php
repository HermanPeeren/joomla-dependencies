<?php

/**
 * @package     JoomlaDependencies
 * @license     GNU General Public License version 3 or later
 */

namespace JoomlaDependencies\Plugin\System\Dependencies\Dependency;

\defined('_JEXEC') or die;

enum ProblemType: string
{
    /** A required extension or PHP extension isn't there. */
    case Missing = 'missing';

    /** A required extension, Joomla or PHP is there, in a version outside the constraint. */
    case WrongVersion = 'wrong-version';

    /** The constraint or the installed version can't be parsed. */
    case Unreadable = 'unreadable';

    /** The install would replace an installed extension with an older version. */
    case Downgrade = 'downgrade';

    /** The install would leave an installed extension with a requirement it met before and no longer meets. */
    case BreaksDependent = 'breaks-dependent';
}
