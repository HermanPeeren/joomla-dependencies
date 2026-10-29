<?php

/**
 * @package     JoomlaDependencies
 * @license     GNU General Public License version 3 or later
 */

namespace JoomlaDependencies\Plugin\System\Dependencies\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use JoomlaDependencies\Plugin\System\Dependencies\Dependency\Problem;
use JoomlaDependencies\Plugin\System\Dependencies\Dependency\ProblemType;

/**
 * A Problem as a translated message.
 */
final class ProblemText
{
    public static function of(Problem $problem): string
    {
        $requirement = (string) $problem->requirement;

        return match ($problem->type) {
            ProblemType::Missing         => Text::sprintf('PLG_SYSTEM_DEPENDENCIES_PROBLEM_MISSING', $problem->subject, $requirement),
            ProblemType::WrongVersion    => Text::sprintf('PLG_SYSTEM_DEPENDENCIES_PROBLEM_WRONG_VERSION', $problem->subject, $requirement, $problem->found),
            ProblemType::Unreadable      => Text::sprintf('PLG_SYSTEM_DEPENDENCIES_PROBLEM_UNREADABLE', $problem->subject, $requirement, $problem->found),
            ProblemType::Downgrade       => Text::sprintf('PLG_SYSTEM_DEPENDENCIES_PROBLEM_DOWNGRADE', (string) $problem->requirement->extension, $problem->replaced, $problem->found),
            ProblemType::BreaksDependent => Text::sprintf('PLG_SYSTEM_DEPENDENCIES_PROBLEM_BREAKS_DEPENDENT', $problem->subject, $requirement, $problem->found),
        };
    }
}
