<?php

/**
 * @package     JoomlaDependencies
 * @license     GNU General Public License version 3 or later
 */

namespace JoomlaDependencies\Plugin\System\Dependencies\Dependency;

\defined('_JEXEC') or die;

use JoomlaDependencies\Vendor\Composer\Semver\Comparator;
use JoomlaDependencies\Vendor\Composer\Semver\Semver;
use JoomlaDependencies\Vendor\Composer\Semver\VersionParser;

/**
 * Version constraints with Composer's semantics (^, ~, ranges, ||), via a prefixed copy of composer/semver.
 */
final class VersionConstraint
{
    /**
     * Whether $version satisfies $constraint. An empty constraint accepts any version, even an unparseable one.
     *
     * @throws \UnexpectedValueException  When the constraint or the version can't be parsed
     */
    public static function satisfies(string $version, string $constraint): bool
    {
        if (trim($constraint) === '') {
            return true;
        }

        self::assertValidConstraint($constraint);

        return Semver::satisfies(self::normalize($version), $constraint);
    }

    public static function isValidConstraint(string $constraint): bool
    {
        try {
            self::assertValidConstraint($constraint);

            return true;
        } catch (\UnexpectedValueException) {
            return false;
        }
    }

    public static function isValidVersion(string $version): bool
    {
        try {
            self::normalize($version);

            return true;
        } catch (\UnexpectedValueException) {
            return false;
        }
    }

    /**
     * @throws \UnexpectedValueException  When either version can't be parsed
     */
    public static function isLower(string $version, string $than): bool
    {
        return Comparator::lessThan(self::normalize($version), self::normalize($than));
    }

    /**
     * @throws \UnexpectedValueException
     */
    private static function assertValidConstraint(string $constraint): void
    {
        (new VersionParser())->parseConstraints($constraint);
    }

    /**
     * Joomla versions are mostly semver, but extensions use anything: "1.0", "v2.1.3", "4.0.0-beta1".
     *
     * @throws \UnexpectedValueException
     */
    private static function normalize(string $version): string
    {
        $version = trim($version);

        if ($version === '') {
            throw new \UnexpectedValueException('Empty version');
        }

        return (new VersionParser())->normalize($version);
    }
}
