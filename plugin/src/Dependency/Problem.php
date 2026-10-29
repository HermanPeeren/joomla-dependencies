<?php

/**
 * @package     JoomlaDependencies
 * @license     GNU General Public License version 3 or later
 */

namespace JoomlaDependencies\Plugin\System\Dependencies\Dependency;

\defined('_JEXEC') or die;

/**
 * One reason an install can't go ahead. The English message is a fallback; the plugin can build a translated one
 * from the same fields.
 */
final readonly class Problem
{
    /**
     * @param  string        $subject      The extension that has the requirement, as its name
     * @param  string|null   $found        The version the site has, or would have; null when absent
     * @param  string|null   $replaced     For a downgrade: the installed version that would be replaced
     */
    public function __construct(
        public ProblemType $type,
        public string $subject,
        public Requirement $requirement,
        public ?string $found = null,
        public ?string $replaced = null
    ) {
    }

    public function message(): string
    {
        return match ($this->type) {
            ProblemType::Missing => \sprintf('%s needs %s, which is not installed.', $this->subject, $this->requirement),
            ProblemType::WrongVersion => \sprintf(
                '%s needs %s, but version %s is installed.',
                $this->subject,
                $this->requirement,
                $this->found
            ),
            ProblemType::Unreadable => \sprintf(
                '%s needs %s, and version "%s" can\'t be compared with that.',
                $this->subject,
                $this->requirement,
                $this->found ?? ''
            ),
            ProblemType::Downgrade => \sprintf(
                'This would replace %s %s with the older version %s.',
                $this->requirement->extension,
                $this->replaced,
                $this->found
            ),
            ProblemType::BreaksDependent => \sprintf(
                '%s needs %s, and this would install version %s.',
                $this->subject,
                $this->requirement,
                $this->found
            ),
        };
    }

    public function __toString(): string
    {
        return $this->message();
    }
}
