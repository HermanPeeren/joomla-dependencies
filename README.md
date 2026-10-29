# Joomla dependencies

A prototype of dependency management between Joomla extensions, without a change to Joomla core.

An extension declares in its manifest what it needs:

```xml
<dependencies>
    <dependency type="library" element="shared" version="^1.0" />
    <dependency type="php-extension" element="sodium" />
</dependencies>
```

The prototype then:

- refuses to install an extension whose dependencies are missing or too old;
- refuses to uninstall an extension that another installed extension still needs, including when a package that carries it is uninstalled;
- still lets a shared library be updated.

What it cannot do without core changes is part of the result: the prototype is meant to find out exactly which hooks Joomla core would need. See [docs/plan.md](docs/plan.md) for the design, the phases and the test scenarios.

## Status

- **Phase 0, done:** test site, fixture extensions and an event probe; findings in [docs/event-probe.md](docs/event-probe.md).
- **Phase 1, done:** reading `<dependencies>` from manifests (packages included), version constraints, and the install check, all in plain PHP with unit tests. See `plugin/src/Dependency/`.
- **Phase 2, done:** `plg_system_dependencies` records every extension's `<dependencies>`, locks what others need, unlocks a library around its own update, and lets a package take along children only it needed. `php tools/scenarios.php` runs the command-line scenarios against the test site.

## Working on it

Requirements: PHP 8.3 or newer with `zip`, Composer, a MySQL or MariaDB server, and `curl` and `unzip` on the command line.

```bash
composer install                             # also copies composer/semver into plugin/vendor-prefixed/
composer test                                # PHPUnit; needs no Joomla site
ADMIN_PASSWORD=... tools/install-joomla.sh   # installs the latest stable Joomla into joomla/
php tools/build.php                          # builds the plugin into dist/, fixtures and probe into fixtures/dist/ and probe/dist/
php tools/scenarios.php                      # command-line scenarios against a fresh test site
```

The plugin uses `composer/semver` for version constraints. Another extension, or a later Joomla, may load its own copy, so `tools/prefix-vendor.php` copies it under the namespace `JoomlaDependencies\Vendor\`, and the plugin uses only that copy. That is the advice the prototype will give other developers, applied to itself.

`tools/install-joomla.sh` takes the Super User from `ADMIN_USERNAME` (default `admin`) and `ADMIN_PASSWORD` (required, at least 12 characters). It reads the database connection from `DB_HOST`, `DB_USER`, `DB_PASS` and `DB_NAME`; those defaults suit a local WAMP or XAMPP. The `joomla/` folder and the generated `cypress.env.json` are git-ignored.

### Fixtures

Small extensions that exist only to be installed and uninstalled in the test scenarios of the plan:

| Fixture | What it is |
|---|---|
| `lib_shared` 1.0.0, 1.1.0, 1.2.0, 2.0.0 | the shared library |
| `com_a`, `com_b` | each needs `lib_shared ^1.0` |
| `com_c` | needs `com_a >=1.0` and the PHP extension `sodium` |
| `pkg_a` | `lib_shared` 1.1.0 + `com_a` |
| `pkg_b` | `lib_shared` + `com_b`, built twice: with `lib_shared` 1.0.0 and with 1.1.0 |

### The event probe

`pkg_depprobe` installs three plugins, in the `system`, `installer` and `extension` groups, that only log which installer events they receive, to `joomla/administrator/logs/depprobe.log`. It answers whether one system plugin could do the work of an installer plugin plus an extension plugin. The findings are in [docs/event-probe.md](docs/event-probe.md).

## License

GPL-3.0-or-later.
