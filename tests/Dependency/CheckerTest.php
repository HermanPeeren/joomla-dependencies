<?php

namespace JoomlaDependencies\Tests\Dependency;

use JoomlaDependencies\Plugin\System\Dependencies\Dependency\Checker;
use JoomlaDependencies\Plugin\System\Dependencies\Dependency\ExtensionKey;
use JoomlaDependencies\Plugin\System\Dependencies\Dependency\ExtensionManifest;
use JoomlaDependencies\Plugin\System\Dependencies\Dependency\InstalledExtension;
use JoomlaDependencies\Plugin\System\Dependencies\Dependency\Problem;
use JoomlaDependencies\Plugin\System\Dependencies\Dependency\ProblemType;
use JoomlaDependencies\Plugin\System\Dependencies\Dependency\Requirement;
use JoomlaDependencies\Plugin\System\Dependencies\Dependency\SiteState;
use PHPUnit\Framework\TestCase;

/**
 * The install checks of the plan's test scenarios, on in-memory sites.
 */
final class CheckerTest extends TestCase
{
    public function testMissingDependencyIsRefused(): void
    {
        // Scenario 1: com_a without lib_shared.
        $problems = $this->check($this->comA(), $this->site());

        $this->assertSame([ProblemType::Missing], $this->types($problems));
        $this->assertSame('com_a needs library shared ^1.0, which is not installed.', $problems[0]->message());
    }

    public function testInstalledDependencyIsAccepted(): void
    {
        $problems = $this->check($this->comA(), $this->site([$this->shared('1.1.0')]));

        $this->assertSame([], $problems);
    }

    public function testTooOldDependencyIsRefused(): void
    {
        $comA     = $this->manifest('component', 'com_a', '1.0.0', [Requirement::extension($this->sharedKey(), '^1.1')]);
        $problems = $this->check($comA, $this->site([$this->shared('1.0.0')]));

        $this->assertSame([ProblemType::WrongVersion], $this->types($problems));
        $this->assertSame('com_a needs library shared ^1.1, but version 1.0.0 is installed.', $problems[0]->message());
    }

    public function testAPackageMeetsItsOwnChildrensRequirements(): void
    {
        // Scenario 3: pkg_a carries lib_shared 1.1.0 and com_a.
        $problems = $this->check($this->pkgA('1.1.0'), $this->site());

        $this->assertSame([], $problems);
    }

    public function testAPackageCarryingAnOlderLibraryIsADowngrade(): void
    {
        // Scenario 4: pkg_b with lib_shared 1.0.0 after pkg_a installed 1.1.0.
        $site     = $this->site([$this->shared('1.1.0'), $this->installed($this->comA())]);
        $problems = $this->check($this->pkgB('1.0.0'), $site);

        $this->assertSame([ProblemType::Downgrade], $this->types($problems));
        $this->assertSame('This would replace library shared 1.1.0 with the older version 1.0.0.', $problems[0]->message());
    }

    public function testAPackageCarryingTheSameLibraryVersionIsAccepted(): void
    {
        // Scenario 5.
        $site = $this->site([$this->shared('1.1.0'), $this->installed($this->comA())]);

        $this->assertSame([], $this->check($this->pkgB('1.1.0'), $site));
    }

    public function testUpdatingALibraryBeyondADependentsConstraintIsRefused(): void
    {
        // Scenario 11: lib_shared 2.0.0 while com_a needs ^1.0.
        $site     = $this->site([$this->shared('1.1.0'), $this->installed($this->comA())]);
        $problems = $this->check($this->manifest('library', 'shared', '2.0.0'), $site);

        $this->assertSame([ProblemType::BreaksDependent], $this->types($problems));
        $this->assertSame('com_a needs library shared ^1.0, and this would install version 2.0.0.', $problems[0]->message());
    }

    public function testUpdatingALibraryWithinConstraintsIsAccepted(): void
    {
        // Scenario 9.
        $site = $this->site([$this->shared('1.1.0'), $this->installed($this->comA())]);

        $this->assertSame([], $this->check($this->manifest('library', 'shared', '1.2.0'), $site));
    }

    public function testADependencyThatWasAlreadyBrokenDoesNotBlock(): void
    {
        // com_a needs ^1.0 but the site already has 2.0.0: installing 2.1.0 doesn't make it worse.
        $site = $this->site([$this->shared('2.0.0'), $this->installed($this->comA())]);

        $this->assertSame([], $this->check($this->manifest('library', 'shared', '2.1.0'), $site));
    }

    public function testMissingPhpExtensionIsRefused(): void
    {
        // Scenario 12: com_c needs com_a and sodium.
        $comC = $this->manifest('component', 'com_c', '1.0.0', [
            Requirement::extension(ExtensionKey::of('component', 'com_a'), '>=1.0'),
            Requirement::phpExtension('sodium'),
        ]);

        $site     = $this->site([$this->shared('1.1.0'), $this->installed($this->comA())], phpExtensions: ['zip' => '']);
        $problems = $this->check($comC, $site);

        $this->assertSame([ProblemType::Missing], $this->types($problems));
        $this->assertSame('com_c needs PHP extension sodium, which is not installed.', $problems[0]->message());
    }

    public function testJoomlaAndPhpVersions(): void
    {
        $manifest = $this->manifest('component', 'com_x', '1.0.0', [
            Requirement::joomla('^6.1'),
            Requirement::php('>=8.4'),
        ]);

        $problems = $this->check($manifest, $this->site(joomla: '6.2.0', php: '8.3.6'));

        $this->assertSame([ProblemType::WrongVersion], $this->types($problems));
        $this->assertSame('com_x needs PHP >=8.4, but version 8.3.6 is installed.', $problems[0]->message());
    }

    public function testAnUnreadableInstalledVersionIsReported(): void
    {
        $problems = $this->check($this->comA(), $this->site([$this->shared('october')]));

        $this->assertSame([ProblemType::Unreadable], $this->types($problems));
    }

    public function testADowngradeOfAnExtensionNobodyNeedsIsAllowed(): void
    {
        $site = $this->site([$this->shared('1.1.0')]);

        $this->assertSame([], $this->check($this->manifest('library', 'shared', '1.0.0'), $site));
    }

    public function testAReinstallOfTheSameVersionIsNotADowngrade(): void
    {
        $site = $this->site([$this->shared('1.1.0')]);

        $this->assertSame([], $this->check($this->manifest('library', 'shared', '1.1.0'), $site));
    }

    public function testDependentsOf(): void
    {
        $site = $this->site([
            $this->shared('1.1.0'),
            $this->installed($this->comA()),
            $this->installed($this->manifest('component', 'com_b', '1.0.0', [Requirement::extension($this->sharedKey(), '^1.0')])),
        ]);

        $names = array_map(static fn (array $pair): string => $pair[0]->name, $site->dependentsOf($this->sharedKey()));

        $this->assertSame(['com_a', 'com_b'], $names);
    }

    /**
     * @return Problem[]
     */
    private function check(ExtensionManifest $incoming, SiteState $site): array
    {
        return (new Checker())->checkInstall($incoming, $site);
    }

    /**
     * @param  Problem[]  $problems
     *
     * @return ProblemType[]
     */
    private function types(array $problems): array
    {
        return array_map(static fn (Problem $problem): ProblemType => $problem->type, $problems);
    }

    /**
     * @param  InstalledExtension[]   $extensions
     * @param  array<string, string>  $phpExtensions
     */
    private function site(array $extensions = [], string $joomla = '6.2.0', string $php = '8.3.6', array $phpExtensions = ['sodium' => '']): SiteState
    {
        return SiteState::create($extensions, $joomla, $php, $phpExtensions);
    }

    private function sharedKey(): ExtensionKey
    {
        return ExtensionKey::of('library', 'shared');
    }

    private function shared(string $version): InstalledExtension
    {
        return new InstalledExtension($this->sharedKey(), 'Library shared', $version);
    }

    private function comA(): ExtensionManifest
    {
        return $this->manifest('component', 'com_a', '1.0.0', [Requirement::extension($this->sharedKey(), '^1.0')]);
    }

    private function pkgA(string $sharedVersion): ExtensionManifest
    {
        return $this->manifest('package', 'pkg_a', '1.0.0', [], [
            $this->manifest('library', 'shared', $sharedVersion),
            $this->comA(),
        ]);
    }

    private function pkgB(string $sharedVersion): ExtensionManifest
    {
        return $this->manifest('package', 'pkg_b', '1.0.0', [], [
            $this->manifest('library', 'shared', $sharedVersion),
            $this->manifest('component', 'com_b', '1.0.0', [Requirement::extension($this->sharedKey(), '^1.0')]),
        ]);
    }

    /**
     * @param  Requirement[]        $requirements
     * @param  ExtensionManifest[]  $children
     */
    private function manifest(string $type, string $element, string $version, array $requirements = [], array $children = []): ExtensionManifest
    {
        $name = $type === 'library' ? 'Library ' . $element : $element;

        return new ExtensionManifest(ExtensionKey::of($type, $element), $name, $version, $requirements, $children);
    }

    private function installed(ExtensionManifest $manifest): InstalledExtension
    {
        return InstalledExtension::fromManifest($manifest);
    }
}
