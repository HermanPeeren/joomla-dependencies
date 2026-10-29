<?php

namespace JoomlaDependencies\Tests\Dependency;

use JoomlaDependencies\Plugin\System\Dependencies\Dependency\ExtensionKey;
use JoomlaDependencies\Plugin\System\Dependencies\Dependency\ManifestReader;
use JoomlaDependencies\Plugin\System\Dependencies\Dependency\RequirementKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ManifestReaderTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/jdep-test-' . bin2hex(random_bytes(4));
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($this->tmp);
    }

    /**
     * The element each installer adapter derives, so the key matches the #__extensions row.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function elements(): array
    {
        return [
            'component, from name'      => ['<extension type="component"><name>Foo Bar</name></extension>', 'foo.xml', 'component:com_foobar::'],
            'component, already com_'   => ['<extension type="component"><name>com_foo</name></extension>', 'foo.xml', 'component:com_foo::'],
            'component, <element>'      => ['<extension type="component"><name>Foo</name><element>com_bar</element></extension>', 'foo.xml', 'component:com_bar::'],
            'library'                   => ['<extension type="library"><name>X</name><libraryname>yepr/gen</libraryname></extension>', 'x.xml', 'library:yepr/gen::'],
            'plugin'                    => ['<extension type="plugin" group="System"><name>X</name><files><folder plugin="foo">services</folder></files></extension>', 'foo.xml', 'plugin:foo:system:'],
            'module, files attribute'   => ['<extension type="module" client="site"><name>X</name><files><filename module="Mod_Foo">mod_foo.php</filename></files></extension>', 'x.xml', 'module:mod_foo::0'],
            'module, <element>, admin'  => ['<extension type="module" client="administrator"><name>X</name><element>mod_bar</element><files><folder>src</folder></files></extension>', 'x.xml', 'module:mod_bar::1'],
            'package'                   => ['<extension type="package"><name>X</name><packagename>foo</packagename></extension>', 'pkg_foo.xml', 'package:pkg_foo::'],
            'file, from file name'      => ['<extension type="file"><name>Whatever</name></extension>', 'files_tools.xml', 'file:files_tools::'],
            'language, from tag'        => ['<extension type="language" client="site"><name>Dutch</name><tag>nl-NL</tag></extension>', 'langmetadata.xml', 'language:nl-nl::0'],
            'template'                  => ['<extension type="template" client="site"><name>My Theme</name></extension>', 'templateDetails.xml', 'template:mytheme::0'],
        ];
    }

    #[DataProvider('elements')]
    public function testDerivesTheElementLikeJoomla(string $xml, string $fileName, string $expectedId): void
    {
        $manifest = (new ManifestReader())->fromXml(new \SimpleXMLElement($xml), $fileName);

        $this->assertSame($expectedId, $manifest->key->id());
    }

    public function testReadsDependencies(): void
    {
        $manifest = (new ManifestReader())->fromXml(new \SimpleXMLElement(<<<'XML'
            <extension type="component">
                <name>com_example</name>
                <version>1.2.3</version>
                <dependencies>
                    <dependency type="library" element="shared" version="^1.0" />
                    <dependency type="plugin" folder="system" element="foo" />
                    <dependency type="module" client="administrator" element="mod_bar" version="&gt;=2" />
                    <dependency type="joomla" version="^6.1" />
                    <dependency type="php" version="&gt;=8.3" />
                    <dependency type="php-extension" element="Sodium" />
                </dependencies>
            </extension>
            XML));

        $this->assertSame('1.2.3', $manifest->version);
        $this->assertSame([], $manifest->warnings);
        $this->assertSame(
            ['library shared ^1.0', 'plugin system/foo', 'module mod_bar (administrator) >=2', 'Joomla ^6.1', 'PHP >=8.3', 'PHP extension sodium'],
            array_map('strval', $manifest->requirements)
        );
        $this->assertTrue($manifest->requirements[0]->targets(ExtensionKey::of('library', 'shared')));
        $this->assertSame(RequirementKind::PhpExtension, $manifest->requirements[5]->kind);
    }

    public function testInvalidDependenciesBecomeWarnings(): void
    {
        $manifest = (new ManifestReader())->fromXml(new \SimpleXMLElement(<<<'XML'
            <extension type="component">
                <name>com_example</name>
                <dependencies>
                    <dependency type="widget" element="foo" />
                    <dependency type="plugin" element="no-folder" />
                    <dependency type="php-extension" />
                    <dependency type="library" element="shared" />
                </dependencies>
            </extension>
            XML));

        $this->assertCount(1, $manifest->requirements);
        $this->assertCount(3, $manifest->warnings);
        $this->assertStringContainsString('Unknown extension type "widget"', $manifest->warnings[0]);
    }

    public function testAManifestWithoutDependenciesHasNone(): void
    {
        $manifest = (new ManifestReader())->fromXml(new \SimpleXMLElement('<extension type="library"><name>X</name><libraryname>x</libraryname></extension>'));

        $this->assertSame([], $manifest->requirements);
        $this->assertSame([], $manifest->warnings);
    }

    public function testFindsTheManifestInAFolderOrOneLevelDown(): void
    {
        mkdir($this->tmp . '/sub');
        file_put_contents($this->tmp . '/readme.xml', '<notes/>');
        file_put_contents($this->tmp . '/sub/shared.xml', $this->library('shared', '1.0.0'));

        $manifest = (new ManifestReader())->fromDirectory($this->tmp);

        $this->assertSame('library:shared::', $manifest->key->id());
    }

    public function testAFolderWithoutManifestThrows(): void
    {
        $this->expectException(\RuntimeException::class);

        (new ManifestReader())->fromDirectory($this->tmp);
    }

    public function testReadsPackageChildrenFromZipsAndFoldersInAnUnpackedPackage(): void
    {
        mkdir($this->tmp . '/packages/com_a', 0777, true);
        $this->zip($this->tmp . '/packages/lib_shared.zip', ['shared.xml' => $this->library('shared', '1.1.0')]);
        file_put_contents($this->tmp . '/packages/com_a/a.xml', $this->component('com_a', '^1.0'));
        file_put_contents($this->tmp . '/pkg_a.xml', $this->package('a', 'packages', [
            ['library', 'shared', 'lib_shared.zip'],
            ['component', 'com_a', 'com_a'],
        ]));

        $manifest = (new ManifestReader())->fromDirectory($this->tmp);

        $this->assertSame('package:pkg_a::', $manifest->key->id());
        $this->assertSame([], $manifest->warnings);
        $this->assertSame(
            ['package:pkg_a::', 'library:shared::', 'component:com_a::'],
            array_map(static fn ($m) => $m->key->id(), $manifest->flatten())
        );
        $this->assertSame('1.1.0', $manifest->children[0]->version);
        $this->assertSame('library shared ^1.0', (string) $manifest->children[1]->requirements[0]);
    }

    public function testReadsAPackageZipWithNestedChildZips(): void
    {
        $child = $this->tmp . '/lib.zip';
        $this->zip($child, ['shared.xml' => $this->library('shared', '2.0.0')]);
        $this->zip($this->tmp . '/pkg.zip', [
            'pkg_b.xml'      => $this->package('b', '', [['library', 'shared', 'lib_shared.zip']]),
            'lib_shared.zip' => file_get_contents($child),
        ]);

        $manifest = (new ManifestReader())->fromZip($this->tmp . '/pkg.zip');

        $this->assertCount(1, $manifest->children);
        $this->assertSame('2.0.0', $manifest->children[0]->version);
    }

    public function testReadsABundledDependency(): void
    {
        mkdir($this->tmp . '/library');
        $this->zip($this->tmp . '/library/lib_shared-1.1.0.zip', ['shared.xml' => $this->library('shared', '1.1.0')]);
        file_put_contents($this->tmp . '/a.xml', '<extension type="component"><name>com_a</name><version>1.0.0</version><dependencies>'
            . '<dependency type="library" element="shared" version="^1.0" bundled="library/lib_shared-1.1.0.zip" /></dependencies></extension>');

        $manifest = (new ManifestReader())->fromDirectory($this->tmp);

        $this->assertSame([], $manifest->warnings);
        $this->assertCount(1, $manifest->bundled);
        $this->assertSame('1.1.0', $manifest->bundled[0]->version);
        $this->assertSame(['component:com_a::'], array_map(static fn ($m) => $m->key->id(), $manifest->flatten()));
    }

    public function testABundledFileThatIsAnotherExtensionBecomesAWarning(): void
    {
        $this->zip($this->tmp . '/other.zip', ['other.xml' => $this->library('other', '1.0.0')]);
        file_put_contents($this->tmp . '/a.xml', '<extension type="component"><name>com_a</name><dependencies>'
            . '<dependency type="library" element="shared" bundled="other.zip" /></dependencies></extension>');

        $manifest = (new ManifestReader())->fromDirectory($this->tmp);

        $this->assertSame([], $manifest->bundled);
        $this->assertStringContainsString('library other, which no <dependency> names', $manifest->warnings[0]);
    }

    public function testABrokenPackageChildBecomesAWarning(): void
    {
        $this->zip($this->tmp . '/plg.zip', ['foo.xml' => '<extension type="plugin" group="system"><name>No files</name></extension>']);
        file_put_contents($this->tmp . '/pkg_a.xml', $this->package('a', '', [['plugin', 'foo', 'plg.zip']]));

        $manifest = (new ManifestReader())->fromDirectory($this->tmp);

        $this->assertSame([], $manifest->children);
        $this->assertStringContainsString('A plugin needs an element', $manifest->warnings[0]);
    }

    public function testAMissingPackageChildBecomesAWarning(): void
    {
        file_put_contents($this->tmp . '/pkg_a.xml', $this->package('a', '', [['library', 'shared', 'lib_shared.zip']]));

        $manifest = (new ManifestReader())->fromDirectory($this->tmp);

        $this->assertSame([], $manifest->children);
        $this->assertStringContainsString('lib_shared.zip not found', $manifest->warnings[0]);
    }

    private function library(string $element, string $version): string
    {
        return "<extension type=\"library\"><name>Library $element</name><libraryname>$element</libraryname><version>$version</version></extension>";
    }

    private function component(string $element, string $sharedConstraint): string
    {
        return "<extension type=\"component\"><name>$element</name><version>1.0.0</version>"
            . "<dependencies><dependency type=\"library\" element=\"shared\" version=\"$sharedConstraint\" /></dependencies></extension>";
    }

    /**
     * @param  array<int, array{string, string, string}>  $children  type, id, file
     */
    private function package(string $name, string $folder, array $children): string
    {
        $files = '';

        foreach ($children as [$type, $id, $file]) {
            $files .= "<file type=\"$type\" id=\"$id\">$file</file>";
        }

        $folderAttribute = $folder !== '' ? " folder=\"$folder\"" : '';

        return "<extension type=\"package\"><name>Package $name</name><packagename>$name</packagename><version>1.0.0</version>"
            . "<files$folderAttribute>$files</files></extension>";
    }

    /**
     * @param  array<string, string>  $files
     */
    private function zip(string $path, array $files): void
    {
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();
    }
}
