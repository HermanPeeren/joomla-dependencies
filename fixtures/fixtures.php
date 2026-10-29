<?php

/**
 * The fixture extensions for the test scenarios in docs/plan.md.
 *
 * tools/build.php turns each entry into an installable zip in fixtures/dist/. Fixtures carry no code that
 * does anything: they exist to be installed, updated and uninstalled.
 */

$needsShared = [
    ['type' => 'library', 'element' => 'shared', 'version' => '^1.0'],
];

return [
    'libraries' => [
        'shared' => ['1.0.0', '1.1.0', '1.2.0', '2.0.0'],
    ],

    'components' => [
        'com_a' => ['version' => '1.0.0', 'dependencies' => $needsShared],
        'com_b' => ['version' => '1.0.0', 'dependencies' => $needsShared],
        'com_c' => [
            'version'      => '1.0.0',
            'dependencies' => [
                ['type' => 'component', 'element' => 'com_a', 'version' => '>=1.0'],
                ['type' => 'php-extension', 'element' => 'sodium'],
            ],
        ],
        // A PHP extension no PHP has, for a refusal that doesn't depend on which PHP runs the install.
        'com_d' => [
            'version'      => '1.0.0',
            'dependencies' => [
                ['type' => 'php-extension', 'element' => 'imaginary'],
            ],
        ],
    ],

    // Zip name => package; children are names of zips built above.
    'packages' => [
        'pkg_a-1.0.0' => [
            'element'  => 'a',
            'version'  => '1.0.0',
            'children' => ['lib_shared-1.1.0', 'com_a-1.0.0'],
        ],
        'pkg_b-1.0.0-with-shared-1.0.0' => [
            'element'  => 'b',
            'version'  => '1.0.0',
            'children' => ['lib_shared-1.0.0', 'com_b-1.0.0'],
        ],
        'pkg_b-1.0.0-with-shared-1.1.0' => [
            'element'  => 'b',
            'version'  => '1.0.0',
            'children' => ['lib_shared-1.1.0', 'com_b-1.0.0'],
        ],
    ],
];
