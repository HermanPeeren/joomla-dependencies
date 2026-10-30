# Results

The prototype after phases 0–5, on Joomla 6.2.0 and PHP 8.3, 29–30 October 2026.

## In short

A single system plugin, `plg_system_dependencies`, gives Joomla declarative dependencies between extensions **without a core change**, and it works for what the plan set out:

- **Refusing installs:** an install from the install screen (upload, folder, URL, Install from Web) is refused when a dependency is missing or has the wrong version, when it would downgrade an extension others need, or when it would break an installed extension.
- **Keeping shared extensions:** an extension that others need can't be uninstalled, also not by a package that carries it. The last package that needs a shared library still takes it along.
- **Library updates:** a shared library can still be updated, through every route.
- **Visibility:** a "Dependencies" tab on System → Install → Extensions and a `dependencies:status` CLI command show what is declared, what is unmet and what is kept.

Where Joomla gives a plugin no way in, the prototype can only warn afterwards or not explain itself. Those places are the list of core changes below.

## Test scenarios

Routes: **CLI** = `php cli/joomla.php` (checked automatically by `tools/scenarios.php`); **screen** = the install screen in the browser; **update view** = System → Update → Extensions in the browser.

| # | Scenario | Route | Result |
|---|---|---|---|
| 1 | `com_a` without `lib_shared` | screen | refused: "com_a needs library shared ^1.0, which is not installed." |
| 2 | same | CLI | installed, with a warning: the CLI has no veto |
| 3 | `pkg_a` (`lib_shared` 1.1.0 + `com_a`) | CLI, screen | installed; `lib_shared` locked; the package meets its own children's requirements |
| 4 | `pkg_b` carrying `lib_shared` 1.0.0 after 1.1.0 | screen | refused as a downgrade of an extension others depend on |
| 5 | `pkg_b` carrying `lib_shared` 1.1.0 | CLI, screen | installed; one library row, still locked, now owned by `pkg_b` |
| 6 | uninstall `pkg_a` while `com_b` needs the library | CLI | `pkg_a` and `com_a` removed; the library kept, with "still need it: com_b"; its `package_id` moved to `pkg_b` |
| 7 | uninstall `pkg_b` next | CLI | everything removed, library included; no locks left |
| 8 | uninstall the library while `com_a` and `com_b` need it | CLI (Manage: same code) | refused, but **only with Joomla's generic "is locked" message**: see gap 2 |
| 9 | update `lib_shared` 1.1 → 1.2 | update view, CLI | updated; one row; locked again; given back to `pkg_b` |
| 10 | update `lib_shared` to 2.0 while two extensions need `^1.0` | update view, CLI | updated (no veto there), followed by a warning for each broken extension |
| 11 | same, via the install screen | screen | refused: "com_a needs library shared ^1.0, and this would install version 2.0.0." |
| 12 | `com_d` needs a PHP extension that doesn't exist | screen | refused |
| 13 | install the plugin on a site that already has `pkg_a` | CLI | dependencies read from the installed manifests; the library locked |
| 14 | uninstall the plugin | CLI | its locks removed; core extensions still locked; tables dropped |
| 15 | an extension with `<dependencies>` on a site without the plugin | CLI | installs as before |

### The generator family (phase 5)

Exten-gen 1.6.6 carries `lib_yepr_gen` as a zip inside the component and installs it from its own `postflight()`. Its manifest has no `<dependencies>`; `tools/eval-variants.php` makes two variants that do.

| Step | Result |
|---|---|
| Unmodified Exten-gen, plugin installed | installs as before; nothing locked; the plugin and the library's own uninstall guard don't interfere |
| Variant declaring `yepr_gen ^0.19.1`, fresh site, install screen | **refused**: the library is missing *before* the install, and only Exten-gen's own script brings it. A false refusal |
| Variant with `bundled="library/lib_yepr_gen-0.19.1.zip"` | installs; the library comes from Exten-gen's script and is locked |
| Update the library to 0.19.2 | one row, locked again. 0.19.1's own guard still reports "still used by" once: the old library's script runs during the update, as found in the Exten-gen session |
| Uninstall the library while Exten-gen is installed | refused by the lock, before the library's own guard runs |
| Uninstall Exten-gen, then the library | the lock is released; the library uninstalls normally |

The false refusal led to the `bundled` attribute: *"this extension brings this dependency itself, and installs it when the site's version doesn't meet the constraint"*. The checker then counts the bundled extension as part of the install only when the site needs it, the same decision Exten-gen's own script makes.

## Findings

1. **One system plugin is enough.** It receives every installer event on the web and in the CLI, before the plugins of the installer and extension groups (phase 0).
2. **A library update is an uninstall plus an install.** The library gets a new `extension_id`, its `package_id` is reset to 0, and its old script's uninstall routine runs. So:
   - the plugin identifies extensions by type, element, folder and client, never by id;
   - it unlocks a library just before its update and locks it again afterwards. A locked library would otherwise block its own update and leave two rows (phase 0, finding 3);
   - it gives the library back to the latest package that lists it.

   The same mechanism causes [joomla/joomla-cms#35083](https://github.com/joomla/joomla-cms/issues/35083) and [joomla/joomla-cms#39360](https://github.com/joomla/joomla-cms/issues/39360).
3. **`locked` is the only lever against an uninstall.** It works on every route, including a package removing its children. Its drawback: Manage and `extension:remove` check `locked` *before* calling the installer, so no event fires and only Joomla's generic "is locked" message appears. The plugin can only explain itself when a package uninstall reaches the child, and in its Dependencies tab.
4. **Package uninstall needs to know what leaves together.** Before a package goes, the plugin unlocks the children that only extensions leaving with the package need. Without that, a package could never remove its own shared library.
5. **Only the install screen can refuse** (Install from Web included, which goes through the same model). The update view, `extension:install` and discover install can't. There the plugin warns afterwards; a downgrade through the CLI is not even noticed (it breaks no constraint).
6. **A `php-extension` check depends on which PHP runs the install.** On this machine the CLI PHP lacks `sodium` and Apache's PHP has it. So `com_c` installs through the browser and warns through the CLI.
7. **Extensions that bring their own dependency** clash with a pre-install check unless the manifest says so: hence `bundled`.
8. **Plugin pitfalls:** don't call `getManifest()` on the installer passed to an event during an uninstall (it throws); and the plugin also receives the events of its own uninstall, after its tables are gone.
9. **Smaller Joomla observations:**
   - `extension:install --path=` leaves its `install_*` folder next to the zip;
   - an update server file without `<client>` is matched as client 1 (administrator), so a library's updates are never linked to it;
   - `composer/semver` is only a development dependency of Joomla, so the plugin bundles a prefixed copy. Strauss failed on Windows paths; a 70-line script does the prefixing.

## What Joomla core would need

Ordered from most to least useful. Each one removes a workaround from the prototype.

| # | Core change | Why | Fixes as well |
|---|---|---|---|
| 1 | **`<dependencies>` in the manifest, checked by `Installer` itself** in `install()`, `update()` and `discover_install()`, before anything is written | one check for every route: install screen, update view, CLI, Install from Web | findings 5 and 7 (with `bundled`) |
| 2 | **Uninstall refused with a reason**: `InstallerAdapter::uninstall()` asks "who still needs this?", and Manage and the CLI show the answer | replaces the borrowed `locked` flag and its generic message | finding 3 |
| 3 | **Update a library in place** instead of uninstalling and reinstalling it | stable id and `package_id`; no old uninstall routine or SQL during an update; no unlock/relock dance | #35083, #39360; finding 2 |
| 4 | **Packages and children as many-to-many** (a link table instead of `package_id`), and `PackageAdapter` skipping children another installed extension still needs | a shared child belongs to several packages | finding 4 |
| 5 | **Dependencies in the update view**: show (or block) an update that would break an installed extension before downloading it; `#__updates` already has the version | warn before, not after | scenario 10 |
| 6 | **com_joomlaupdate's pre-update check** reads `joomla` and `php` dependencies | today it only knows update servers' `targetplatform` | |
| 7 | **`composer/semver` as a runtime dependency** of core | one constraint syntax for everything, the same as Composer's | finding 9 |

Changes 1–4 together are the core proposal for route B ([routes.md](routes.md)); 5–7 can follow. None needs Composer on the site, and all are backwards compatible: an extension without `<dependencies>` behaves as before, and older Joomla versions ignore the element.

## Limits of the prototype

- **Messages are English only:** one en-GB language file.
- **The tables are MySQL only:** no PostgreSQL SQL files.
- **Changes the plugin can't see:** if the plugin is disabled while extensions change, the tables go stale; `dependencies:sync` rebuilds them.
- **A file extension updated through the install screen:** its element comes from the manifest's file name, which the before-install event doesn't pass. Unlocking around an update only matters for libraries, so this has no effect today.
- **Not tested:** discover install; Install from Web against the real JED; uninstall from Manage in the browser (the code path is the same as `extension:remove`, which was tested).
