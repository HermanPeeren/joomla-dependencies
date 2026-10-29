<?php

namespace JoomlaDependencies\Tests\Dependency;

use JoomlaDependencies\Plugin\System\Dependencies\Dependency\Checker;
use JoomlaDependencies\Plugin\System\Dependencies\Dependency\ExtensionManifest;
use JoomlaDependencies\Plugin\System\Dependencies\Dependency\InstalledExtension;
use JoomlaDependencies\Plugin\System\Dependencies\Dependency\ManifestReader;
use JoomlaDependencies\Plugin\System\Dependencies\Dependency\Problem;
use JoomlaDependencies\Plugin\System\Dependencies\Dependency\ProblemType;
use JoomlaDependencies\Plugin\System\Dependencies\Dependency\SiteState;
use PHPUnit\Framework\TestCase;

/**
 * The reader and checker on the fixture zips that tools/build.php makes, i.e. on what the test site will install.
 */
final class FixturesTest extends TestCase
{
    private const DIST = __DIR__ . '/../../fixtures/dist';

    public static function setUpBeforeClass(): void
    {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../tools/build.php'), $output, $exitCode);

        self::assertSame(0, $exitCode, implode("\n", $output));
    }

    public function testEveryFixtureReadsWithoutWarnings(): void
    {
        foreach (glob(self::DIST . '/*.zip') as $zip) {
            $this->assertSame([], $this->read(basename($zip, '.zip'))->warnings, basename($zip));
        }
    }

    public function testPackageAContainsTheLibraryAndComponent(): void
    {
        $ids = array_map(static fn (ExtensionManifest $m): string => $m->key->id() . '@' . $m->version, $this->read('pkg_a-1.0.0')->flatten());

        $this->assertSame(['package:pkg_a::@1.0.0', 'library:shared::@1.1.0', 'component:com_a::@1.0.0'], $ids);
    }

    public function testScenarios(): void
    {
        $empty = SiteState::create([], '6.2.0', PHP_VERSION, ['zip' => '']);

        // 1: com_a alone.
        $this->assertSame([ProblemType::Missing], $this->types($this->read('com_a-1.0.0'), $empty));

        // 3: pkg_a on an empty site.
        $this->assertSame([], $this->types($this->read('pkg_a-1.0.0'), $empty));

        $withA = $this->siteWith($empty, $this->read('pkg_a-1.0.0'));

        // 4 and 5: pkg_b with an older or the same library.
        $this->assertSame([ProblemType::Downgrade], $this->types($this->read('pkg_b-1.0.0-with-shared-1.0.0'), $withA));
        $this->assertSame([], $this->types($this->read('pkg_b-1.0.0-with-shared-1.1.0'), $withA));

        // 9 and 11: library updates within and beyond com_a's ^1.0.
        $this->assertSame([], $this->types($this->read('lib_shared-1.2.0'), $withA));
        $this->assertSame([ProblemType::BreaksDependent], $this->types($this->read('lib_shared-2.0.0'), $withA));

        // 12: com_c needs com_a and sodium; this site has no sodium.
        $this->assertSame([ProblemType::Missing], $this->types($this->read('com_c-1.0.0'), $withA));
    }

    private function read(string $name): ExtensionManifest
    {
        return (new ManifestReader())->fromZip(self::DIST . "/$name.zip");
    }

    private function siteWith(SiteState $site, ExtensionManifest $manifest): SiteState
    {
        return SiteState::create(
            array_map([InstalledExtension::class, 'fromManifest'], $manifest->flatten()),
            $site->joomlaVersion,
            $site->phpVersion,
            $site->phpExtensions
        );
    }

    /**
     * @return ProblemType[]
     */
    private function types(ExtensionManifest $incoming, SiteState $site): array
    {
        return array_map(
            static fn (Problem $problem): ProblemType => $problem->type,
            (new Checker())->checkInstall($incoming, $site)
        );
    }
}
