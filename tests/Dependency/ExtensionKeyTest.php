<?php

namespace JoomlaDependencies\Tests\Dependency;

use JoomlaDependencies\Plugin\System\Dependencies\Dependency\ExtensionKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExtensionKeyTest extends TestCase
{
    public function testElementsCompareCaseInsensitively(): void
    {
        $this->assertTrue(ExtensionKey::of('component', 'com_Foo')->equals(ExtensionKey::of('Component', 'com_foo')));
    }

    public function testFolderOnlyCountsForPlugins(): void
    {
        $this->assertTrue(ExtensionKey::of('library', 'shared', 'ignored')->equals(ExtensionKey::of('library', 'shared')));
        $this->assertFalse(ExtensionKey::of('plugin', 'foo', 'system')->equals(ExtensionKey::of('plugin', 'foo', 'content')));
    }

    public function testClientOnlyCountsForModulesTemplatesAndLanguages(): void
    {
        $this->assertFalse(ExtensionKey::of('module', 'mod_foo', '', 'site')->equals(ExtensionKey::of('module', 'mod_foo', '', 'administrator')));
        $this->assertTrue(ExtensionKey::of('module', 'mod_foo', '', 'site')->equals(ExtensionKey::of('module', 'mod_foo')));
        $this->assertTrue(ExtensionKey::of('template', 'cassiopeia', '', 0)->equals(ExtensionKey::of('template', 'cassiopeia', '', 'site')));
        $this->assertTrue(ExtensionKey::of('component', 'com_foo', '', 'site')->equals(ExtensionKey::of('component', 'com_foo', '', 'administrator')));
    }

    public function testRoundTripsThroughItsId(): void
    {
        foreach ([
            ExtensionKey::of('plugin', 'Foo', 'system'),
            ExtensionKey::of('language', 'nl-NL', '', 'api'),
            ExtensionKey::of('library', 'yepr/gen'),
        ] as $key) {
            $this->assertTrue($key->equals(ExtensionKey::fromId($key->id())));
        }
    }

    public function testReadableForm(): void
    {
        $this->assertSame('library shared', (string) ExtensionKey::of('library', 'shared'));
        $this->assertSame('plugin system/foo', (string) ExtensionKey::of('plugin', 'foo', 'System'));
        $this->assertSame('module mod_foo (administrator)', (string) ExtensionKey::of('module', 'mod_foo', '', 1));
    }

    /**
     * @return array<string, array{string, string, string, string|int|null}>
     */
    public static function invalidKeys(): array
    {
        return [
            'unknown type'      => ['widget', 'foo', '', null],
            'empty element'     => ['library', ' ', '', null],
            'plugin, no folder' => ['plugin', 'foo', '', null],
            'unknown client'    => ['module', 'mod_foo', '', 'cli'],
        ];
    }

    #[DataProvider('invalidKeys')]
    public function testRejectsInvalidKeys(string $type, string $element, string $folder, string|int|null $client): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ExtensionKey::of($type, $element, $folder, $client);
    }
}
