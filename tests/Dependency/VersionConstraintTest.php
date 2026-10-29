<?php

namespace JoomlaDependencies\Tests\Dependency;

use JoomlaDependencies\Plugin\System\Dependencies\Dependency\VersionConstraint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VersionConstraintTest extends TestCase
{
    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function cases(): array
    {
        return [
            'caret, inside'          => ['1.2.0', '^1.0', true],
            'caret, next major'      => ['2.0.0', '^1.0', false],
            'caret below 1.0'        => ['0.19.2', '^0.19.1', true],
            'caret below 1.0, minor' => ['0.20.0', '^0.19.1', false],
            'tilde'                  => ['1.4.9', '~1.4.0', true],
            'range'                  => ['6.2.0', '>=6.1 <7.0', true],
            'or'                     => ['5.4.0', '^4.4 || ^5.0', true],
            'two-part version'       => ['1.0', '>=1.0', true],
            'v prefix'               => ['v2.1.3', '^2.1', true],
            // As in Composer, a lower bound accepts pre-releases of that version; Composer filters stability
            // separately (minimum-stability), which has no counterpart here.
            'pre-release, >='        => ['2.0.0-beta1', '>=2.0.0', true],
            'pre-release, caret'     => ['2.0.0-beta1', '^1.0', false],
            'empty accepts anything' => ['not a version', '', true],
        ];
    }

    #[DataProvider('cases')]
    public function testSatisfies(string $version, string $constraint, bool $expected): void
    {
        $this->assertSame($expected, VersionConstraint::satisfies($version, $constraint));
    }

    public function testUnparseableVersionThrows(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        VersionConstraint::satisfies('october release', '^1.0');
    }

    public function testValidation(): void
    {
        $this->assertTrue(VersionConstraint::isValidConstraint('^1.0 || ~2.3'));
        $this->assertFalse(VersionConstraint::isValidConstraint('^^1'));
        $this->assertTrue(VersionConstraint::isValidVersion('6.2.0'));
        $this->assertFalse(VersionConstraint::isValidVersion(''));
    }

    public function testIsLower(): void
    {
        $this->assertTrue(VersionConstraint::isLower('1.0.0', '1.1.0'));
        $this->assertFalse(VersionConstraint::isLower('1.1.0', '1.1'));
        $this->assertFalse(VersionConstraint::isLower('2.0.0', '1.9.9'));
    }
}
