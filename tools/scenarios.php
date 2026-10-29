<?php

/**
 * Runs the plan's test scenarios that go through the command line (php joomla/cli/joomla.php) against the test
 * site, and checks the database after each step. The install-screen and update-view scenarios are in the browser;
 * see docs/results.md.
 *
 * Needs a fresh test site and built zips:
 *
 *   ADMIN_PASSWORD=... tools/install-joomla.sh --force
 *   php tools/build.php
 *   php tools/scenarios.php
 */

declare(strict_types=1);

$root = \dirname(__DIR__);
$site = $root . '/joomla';

require $site . '/configuration.php';

$config = new JConfig();
$db     = new mysqli($config->host, $config->user, $config->password, $config->db);
$prefix = $config->dbprefix;

$failures = 0;

// ---------------------------------------------------------------------------------------------------------------

step('Install the plugin on a fresh site');
$out = cli("extension:install --path=$root/dist/plg_system_dependencies-0.1.0.zip");
check('installed', str_contains($out, 'installed successfully'), $out);
check('plugin enabled', (int) value("SELECT enabled FROM {$prefix}extensions WHERE type='plugin' AND folder='system' AND element='dependencies'") === 1);
check('tables exist', value("SHOW TABLES LIKE '{$prefix}dependencies_edges'") !== null);

step('Scenario 2: com_a without lib_shared, via the CLI');
$out = cli("extension:install --path=$root/fixtures/dist/com_a-1.0.0.zip");
check('installed anyway (the CLI has no veto)', str_contains($out, 'installed successfully'), $out);
check('warns that library shared ^1.0 is missing', str_contains($out, 'com_a needs library shared ^1.0, which is not installed'), $out);
check('edge recorded', edges('component:com_a::') === ['extension library:shared:: ^1.0']);
cli('extension:remove ' . id('component', 'com_a') . ' -n');
check('edge forgotten after uninstall', edges('component:com_a::') === []);

step('Scenario 3: pkg_a (lib_shared 1.1.0 + com_a)');
$out = cli("extension:install --path=$root/fixtures/dist/pkg_a-1.0.0.zip");
check('installed', str_contains($out, 'installed successfully'), $out);
check('no warnings', !str_contains($out, 'WARNING'), $out);
check('lib_shared locked', locked('library', 'shared') === [1]);
check('lock recorded as ours', locks() === ['library:shared::']);
check('lib_shared belongs to pkg_a', packageOf('library', 'shared') === 'pkg_a');

step('Scenario 5: pkg_b carrying lib_shared 1.1.0');
$out = cli("extension:install --path=$root/fixtures/dist/pkg_b-1.0.0-with-shared-1.1.0.zip");
check('installed', str_contains($out, 'installed successfully'), $out);
check('one lib_shared row, still locked', locked('library', 'shared') === [1]);
check('lib_shared now belongs to pkg_b', packageOf('library', 'shared') === 'pkg_b');

step('Scenario 9 (via the CLI): update lib_shared 1.1.0 -> 1.2.0');
$out = cli("extension:install --path=$root/fixtures/dist/lib_shared-1.2.0.zip");
check('installed', str_contains($out, 'installed successfully'), $out);
check('one row, version 1.2.0', versions('library', 'shared') === ['1.2.0']);
check('locked again afterwards', locked('library', 'shared') === [1]);
check('no warnings', !str_contains($out, 'WARNING'), $out);
check('back with pkg_b, the latest package listing it (Joomla had set package_id to 0)', packageOf('library', 'shared') === 'pkg_b');

step('Scenario 10 (via the CLI): update lib_shared to 2.0.0 while com_a and com_b need ^1.0');
$out = cli("extension:install --path=$root/fixtures/dist/lib_shared-2.0.0.zip");
check('installed anyway (the CLI has no veto)', str_contains($out, 'installed successfully'), $out);
check('one row, version 2.0.0', versions('library', 'shared') === ['2.0.0']);
check('warns for com_a', str_contains($out, 'com_a needs library shared ^1.0, but version 2.0.0 is installed'), $out);
check('warns for com_b', str_contains($out, 'com_b needs library shared ^1.0, but version 2.0.0 is installed'), $out);
cli("extension:install --path=$root/fixtures/dist/lib_shared-1.2.0.zip");
check('back to 1.2.0 (a downgrade the CLI cannot refuse)', versions('library', 'shared') === ['1.2.0']);

step('Scenario 8 (via the CLI): uninstall lib_shared while com_a and com_b need it');
$out = cli('extension:remove ' . id('library', 'shared') . ' -n');
check('refused', versions('library', 'shared') === ['1.2.0'], $out);
// extension:remove (and Manage) check `locked` before calling the installer, so no event reaches the plugin and
// only Joomla's own message appears. A core gap: see docs/results.md.
check('Joomla says it is locked, without the reason', str_contains($out, 'is locked and cannot be uninstalled'), $out);

step('Scenario 6: uninstall pkg_a while com_b (pkg_b) needs lib_shared');
$out = cli('extension:remove ' . id('package', 'pkg_a') . ' -n');
check('pkg_a removed', id('package', 'pkg_a') === null, $out);
check('com_a removed', id('component', 'com_a') === null, $out);
check('lib_shared kept', versions('library', 'shared') === ['1.2.0'], $out);
check('says com_b needs it, not com_a', str_contains($out, 'still need it: com_b.'), $out);
check('lib_shared belongs to pkg_b', packageOf('library', 'shared') === 'pkg_b');

step('Scenario 7: uninstall pkg_b, the last one that needs lib_shared');
$out = cli('extension:remove ' . id('package', 'pkg_b') . ' -n');
check('pkg_b removed', id('package', 'pkg_b') === null, $out);
check('com_b removed', id('component', 'com_b') === null, $out);
check('lib_shared removed too', versions('library', 'shared') === [], $out);
check('no locks left', locks() === []);
check('no edges left for the fixtures', edgesLike('component:com_%') === []);

step('Scenario 13: install the plugin on a site that already has pkg_a');
cli('extension:remove ' . id('plugin', 'dependencies', 'system') . ' -n');
check('plugin uninstalled', id('plugin', 'dependencies', 'system') === null);
cli("extension:install --path=$root/fixtures/dist/pkg_a-1.0.0.zip");
check('without the plugin, nothing is locked', locked('library', 'shared') === [0]);
$coreLocked = (int) value("SELECT COUNT(*) FROM {$prefix}extensions WHERE locked = 1");
$out        = cli("extension:install --path=$root/dist/plg_system_dependencies-0.1.0.zip");
check('edges built from installed manifests', edges('component:com_a::') === ['extension library:shared:: ^1.0']);
check('lib_shared locked', locked('library', 'shared') === [1]);
check('reports one lock', str_contains($out, 'locked 1 extensions'), $out);

step('Scenario 14: uninstall the plugin');
cli('extension:remove ' . id('plugin', 'dependencies', 'system') . ' -n');
check('lib_shared unlocked', locked('library', 'shared') === [0]);
check('core extensions still locked', (int) value("SELECT COUNT(*) FROM {$prefix}extensions WHERE locked = 1") === $coreLocked);
check('tables dropped', value("SHOW TABLES LIKE '{$prefix}dependencies_edges'") === null);

step('Scenario 15: an extension with <dependencies> on a site without the plugin');
$out = cli("extension:install --path=$root/fixtures/dist/com_d-1.0.0.zip");
check('installs as before', str_contains($out, 'installed successfully'), $out);

echo "\n" . ($failures === 0 ? "All checks passed.\n" : "$failures check(s) failed.\n");
exit($failures === 0 ? 0 : 1);

// ---------------------------------------------------------------------------------------------------------------

function cli(string $arguments): string
{
    global $site;

    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$site/cli/joomla.php") . " $arguments -n 2>&1", $output);

    // Joomla's CLI wraps long lines; join them so a message can be found in one piece.
    $text = preg_replace('/\e\[[0-9;]*m/', '', implode("\n", $output));

    return (string) preg_replace('/\s+/', ' ', $text);
}

function step(string $title): void
{
    echo "\n== $title\n";
}

function check(string $label, bool $ok, string $context = ''): void
{
    global $failures;

    echo ($ok ? '  PASS ' : '  FAIL ') . $label . "\n";

    if (!$ok) {
        $failures++;

        if ($context !== '') {
            echo '       ' . substr($context, 0, 600) . "\n";
        }
    }
}

function value(string $sql): mixed
{
    global $db;

    $row = $db->query($sql)->fetch_row();

    return $row[0] ?? null;
}

/**
 * @return array<int, array<string, mixed>>
 */
function rows(string $sql): array
{
    global $db;

    return $db->query($sql)->fetch_all(MYSQLI_ASSOC);
}

function id(string $type, string $element, string $folder = ''): ?int
{
    global $db, $prefix;

    $id = value(\sprintf(
        "SELECT extension_id FROM {$prefix}extensions WHERE type='%s' AND element='%s' AND folder='%s'",
        $db->real_escape_string($type),
        $db->real_escape_string($element),
        $db->real_escape_string($folder)
    ));

    return $id === null ? null : (int) $id;
}

/**
 * @return int[]
 */
function locked(string $type, string $element): array
{
    global $prefix;

    return array_map('intval', array_column(rows("SELECT locked FROM {$prefix}extensions WHERE type='$type' AND element='$element'"), 'locked'));
}

/**
 * @return string[]
 */
function versions(string $type, string $element): array
{
    global $prefix;

    return array_map(
        static fn (array $row): string => json_decode($row['manifest_cache'], true)['version'] ?? '',
        rows("SELECT manifest_cache FROM {$prefix}extensions WHERE type='$type' AND element='$element'")
    );
}

function packageOf(string $type, string $element): ?string
{
    global $prefix;

    return value("SELECT p.element FROM {$prefix}extensions e JOIN {$prefix}extensions p ON p.extension_id = e.package_id WHERE e.type='$type' AND e.element='$element'");
}

/**
 * @return string[]
 */
function edges(string $dependent): array
{
    global $prefix;

    return array_map(
        static fn (array $row): string => trim("{$row['kind']} {$row['target']} {$row['version_constraint']}"),
        rows("SELECT kind, target, version_constraint FROM {$prefix}dependencies_edges WHERE dependent='$dependent' ORDER BY id")
    );
}

/**
 * @return string[]
 */
function edgesLike(string $pattern): array
{
    global $prefix;

    return array_column(rows("SELECT dependent FROM {$prefix}dependencies_edges WHERE dependent LIKE '$pattern'"), 'dependent');
}

/**
 * @return string[]
 */
function locks(): array
{
    global $prefix;

    return array_column(rows("SELECT extension FROM {$prefix}dependencies_locks ORDER BY extension"), 'extension');
}
