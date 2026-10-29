<?php

/**
 * @package     JoomlaDependencies
 * @license     GNU General Public License version 3 or later
 */

namespace JoomlaDependencies\Plugin\System\Dependencies\Dependency;

\defined('_JEXEC') or die;

/**
 * One <dependency> from a manifest: another extension, the Joomla version, the PHP version or a PHP extension,
 * optionally with a version constraint in Composer syntax.
 */
final readonly class Requirement
{
    /**
     * @param  ExtensionKey|null  $extension   For RequirementKind::Extension
     * @param  string             $name        For RequirementKind::PhpExtension, e.g. sodium
     * @param  string             $constraint  '' means any version
     */
    private function __construct(
        public RequirementKind $kind,
        public ?ExtensionKey $extension,
        public string $name,
        public string $constraint
    ) {
    }

    public static function extension(ExtensionKey $extension, string $constraint = ''): self
    {
        return new self(RequirementKind::Extension, $extension, '', trim($constraint));
    }

    public static function joomla(string $constraint): self
    {
        return new self(RequirementKind::Joomla, null, '', trim($constraint));
    }

    public static function php(string $constraint): self
    {
        return new self(RequirementKind::Php, null, '', trim($constraint));
    }

    public static function phpExtension(string $name, string $constraint = ''): self
    {
        return new self(RequirementKind::PhpExtension, null, strtolower(trim($name)), trim($constraint));
    }

    public function targets(ExtensionKey $key): bool
    {
        return $this->extension !== null && $this->extension->equals($key);
    }

    /**
     * For messages: "library shared ^1.0", "PHP >=8.3", "PHP extension sodium".
     */
    public function __toString(): string
    {
        $subject = match ($this->kind) {
            RequirementKind::Extension    => (string) $this->extension,
            RequirementKind::Joomla       => 'Joomla',
            RequirementKind::Php          => 'PHP',
            RequirementKind::PhpExtension => 'PHP extension ' . $this->name,
        };

        return trim($subject . ' ' . $this->constraint);
    }
}
