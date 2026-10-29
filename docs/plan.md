# Path B prototype: plan

29 October 2026 · Herman Peeren

"Path B" is one of several routes towards dependency management in Joomla that an earlier exploration compared: declarative dependencies in the extension manifest, with the installer keeping track of who needs what. Composer-based routes are the others.

## Goal

A Joomla 6 extension that adds declarative dependencies between extensions **without a core change**:

- An extension declares in its manifest what it needs: other extensions with a version constraint, the Joomla version, the PHP version and PHP extensions.
- Installing an extension whose dependencies are not met is refused, where Joomla allows a plugin to refuse.
- An extension that another installed extension still needs can't be uninstalled, also not by a package that carries it. A library can still be updated.
- Everything the prototype *can't* do without core is written down as a concrete core change. That list, with the prototype as a working demo, is the input for a follow-up to [joomla/rfc#9](https://github.com/joomla/rfc/issues/9).

**Not in scope:** fetching missing dependencies automatically, third-party PHP packages (paths C/D), and resolving several versions at once. The prototype only checks against what is installed or in the package being installed.

## The manifest format

A new `<dependencies>` element in any extension manifest. Joomla ignores elements it doesn't know, so extensions using it still install on sites without the prototype, and on older Joomla versions.

```xml
<dependencies>
    <dependency type="library" element="yepr_gen" version="^0.19.1" />
    <dependency type="component" element="com_example" version="&gt;=2.0" />
    <dependency type="plugin" folder="system" element="example" version="^1.2" />
    <dependency type="module" client="site" element="mod_example" />
    <dependency type="package" element="pkg_example" version="^3.0" />
    <dependency type="joomla" version="^6.1" />
    <dependency type="php" version="&gt;=8.3" />
    <dependency type="php-extension" element="sodium" />
</dependencies>
```

- **Identity** follows `#__extensions`: `type` + `element`, plus `folder` for plugins and `client` (`site`/`administrator`) for modules, templates and languages. The `element` is the same value as in `#__extensions` (`yepr_gen` for a library, not `lib_yepr_gen`).
- **`version`** is an optional constraint in Composer syntax (`^`, `~`, ranges, `||`), checked against the version in `#__extensions.manifest_cache`. Without it, any installed version counts.
- **Constraint library:** `composer/semver`. Joomla ships it only as a development dependency, so the prototype bundles its own copy with the namespace prefixed (PHP-Scoper or Strauss). That doubles as a test of the path A advice.
- **Left for later, deliberately:** `optional="true"` (suggests), `conflicts`, and what to do with dependencies of disabled extensions (in the prototype, installed counts, enabled or not).

## How it works: one package, two plugins

`pkg_dependencies` (working name) contains:

| Part | Group | Why this group |
|---|---|---|
| `plg_installer_dependencies` | installer | com_installer's install screen only loads `installer` plugins for `onInstallerBeforeInstaller`, the only event that can refuse an install |
| `plg_extension_dependencies` | extension | `Installer` loads `extension` plugins for `onExtensionBefore/After…`, which fire on **every** route: the install screen, updates, discover install, the CLI, and each child of a package |
| shared code (in the package's library or in one of the plugins) | | manifest parsing, constraint checking, the dependency table, lock handling |

One system plugin could in theory listen to everything, because the events go through the application dispatcher. Two plugins in their own groups show more clearly where core hooks would sit, and don't depend on system plugins being loaded in the CLI. Phase 0 checks whether a single plugin works as well; if it does, that is simpler to install.

> **Decided in phase 0:** one system plugin. It receives every installer event on the web and in the CLI, and always first. See [event-probe.md](event-probe.md). Phase 0 also showed that a library update gives the library a new `extension_id` and resets its `package_id`, so the data model below identifies extensions by type and element only.

### Data model

```
#__dependencies_edges
  id
  type, element, folder, client_id                 the dependent
  dep_type         library | component | plugin | module | template | language | file | package | joomla | php | php-extension
  dep_element
  dep_folder       plugins only, else ''
  dep_client_id    modules, templates, languages; else 0
  dep_constraint   '' = any version

#__dependencies_locks
  type, element, folder, client_id                 an extension whose `locked` this prototype set to 1
```

Both tables identify extensions the way Joomla's own `find()` does: by type, element, folder and client. They never use `extension_id`, because a library update gives the library a new one.

The second table matters: core extensions already have `locked = 1`. The prototype only ever unlocks extensions **it** locked.

### The flows

**1. Install through the install screen** (upload, folder, URL, Install from Web): `onInstallerBeforeInstaller`, installer plugin, **can refuse**.
- Find the manifest in the unpacked folder.
- For a package, also read each child's manifest straight from its zip inside the package with `ZipArchive`, without unpacking. Dependencies that the package itself provides count as met.
- Check every `<dependency>` against the site *as it will be after this install*: what is installed, plus or replaced by what is in the package.
- Also refuse when:
  - **a downgrade:** the package carries an older version of an installed library;
  - **a breaking update:** the new version of an installed extension no longer satisfies an installed extension that needs it.
- Return `false` with one message listing every problem: what is missing, which version is needed, and which version is installed.

**2. After every install and update**, on every route: `onExtensionAfterInstall`/`AfterUpdate`, extension plugin.
- Read the manifest from the event's `installer` (a clone of the installer, with the manifest still loaded).
- Replace that extension's rows in `#__dependencies_edges`.
- Recompute the locks: lock every installed extension that something needs, and unlock (only ours) what nothing needs any more.
- For routes that couldn't refuse (CLI, update view, discover), check the dependencies afterwards and show a warning, or a CLI line, for each unmet one.

**3. Updating a locked extension**: `onExtensionBeforeInstall`/`BeforeUpdate` (no veto needed).
- `LibraryAdapter` updates a library by first uninstalling the old one, and `locked` would refuse that. The two library rows of generator-core 0.19.1 would come back.
- So if the incoming manifest is an extension the prototype locked, unlock it now. Step 2 locks it again afterwards. `onExtensionAfterInstall` fires whether the install succeeded or not, so the lock always comes back.
- For a library the element comes from `<libraryname>`; for other types from the usual manifest fields.

**4. Uninstall**: `locked` does the refusing; the plugin explains why.
- `InstallerAdapter::uninstall()` refuses a locked extension on every route: Manage, CLI, and a package removing its children. That package is still removed, and the shared library stays.
- `onExtensionBeforeUninstall` can't refuse, but it can enqueue a message: "Shared Library is still needed by Extension Generator and Generator Generator". Without it, the user only sees Joomla's generic locked message.
- `onExtensionAfterUninstall` (when `removed` is true): delete the extension's edges and recompute the locks, so a library that is no longer needed becomes removable again.
- **After a package uninstall:** children that stayed behind still have the removed package as `package_id`. Joomla treats that as "not blocked", which works, but the prototype resets it to another installed package that lists the child, or to 0.

**5. Installing the prototype**
- Its install script creates the tables, then reads the manifests of all installed extensions and fills the edges. To find each manifest it can reuse `Installer::refreshManifestCache($eid)`, which locates it per type and leaves its path in `getPath('manifest')`; the side effect is a refreshed `manifest_cache`.
- It then applies the locks and reports dependencies that are already unmet.

**6. Uninstalling the prototype**
- Unlock everything in `#__dependencies_locks`, then drop both tables. Its own uninstall must never leave extensions locked.

## What the prototype can't do (the list for core)

What we know now. The prototype will confirm or extend it.

| Gap | Effect in the prototype | Core change |
|---|---|---|
| No veto on updates (System → Update → Extensions), the CLI or discover install | warns afterwards instead of refusing | a stoppable event in `Installer::install()`/`update()`, or dependency checking in `Installer` itself |
| `locked` is the only way to stop an uninstall | Manage shows "Locked: Yes" without the reason; the reason only comes as a message | a stoppable `onExtensionBeforeUninstall`, or a dependency check in `InstallerAdapter::uninstall()` |
| A library update runs a full uninstall of the old version | needs the unlock/relock dance; also the cause of [joomla/joomla-cms#35083](https://github.com/joomla/joomla-cms/issues/35083) and [joomla/joomla-cms#39360](https://github.com/joomla/joomla-cms/issues/39360) | update a library in place, like the other adapters do |
| `package_id` holds one package | the prototype keeps its own picture and repairs `package_id` afterwards | a link table between packages and children, or reference counting |
| No pre-update check for dependencies when Joomla itself is updated | not covered | dependencies of type `joomla` and `php` in com_joomlaupdate's pre-update check |

## Phases

**0. Set-up**
- A new repository (name to decide, e.g. `joomla-dependencies`), with the usual git-ignored `/joomla` test site, installed from the latest stable Joomla release with the `admin` test account.
- Fixture extensions, built as zips by a script:
  - `lib_shared` in versions 1.0, 1.1 and 2.0;
  - `com_a` and `com_b`, each needing `lib_shared ^1.0`;
  - `pkg_a` and `pkg_b`, each containing `lib_shared` and its own component;
  - `com_c` needing `com_a >=1.0` and `php-extension` `sodium`.
- Check whether a single system plugin receives all events, including in the CLI.

**1. Format and checking**
- Manifest parser, including child manifests read from inside a package zip.
- Constraint checking with the scoped `composer/semver`.
- The "site after this install" model.
- PHPUnit tests for all of these, without a database.

**2. Recording and locking**: the extension plugin.
- Edges, locks, the unlock/relock around updates, `package_id` repair.
- Integration tests through Joomla's real `Installer`: a PHP test harness that boots the test site, plus `cli/joomla.php extension:install` and `extension:remove`.

**3. The install veto**: the installer plugin, including the package-with-children check, refusing downgrades and breaking updates.
- Tested through the install screen, in the browser.

**4. Messages and visibility**
- Readable messages in all flows.
- A small overview of the dependency graph and any unmet dependencies. Probably a tab via `onInstallerAddInstallationTab`, or a minimal admin view. Decide in phase 3.

**5. Evaluation**
- Install and uninstall of the prototype itself.
- Try it with the generator family (`lib_yepr_gen`, Exten-gen, Gen-gen) on a copy of a site.
- Update the "for core" list, and write the RFC follow-up.

## Test scenarios (the acceptance list)

| # | Scenario | Expected |
|---|---|---|
| 1 | Install `com_a` without `lib_shared` via the install screen | refused, message names `lib_shared ^1.0` |
| 2 | Same via the CLI | installed, with a warning about the unmet dependency |
| 3 | Install `pkg_a` (carries `lib_shared` 1.1 and `com_a`) | installed; `lib_shared` locked |
| 4 | Install `pkg_b` (carries `lib_shared` 1.0) after `pkg_a` | refused as a downgrade of `lib_shared` |
| 5 | Same, but `pkg_b` carries 1.1 | installed; one `lib_shared` row; still locked |
| 6 | Uninstall `pkg_a` while `pkg_b` is installed | `pkg_a` and `com_a` removed; `lib_shared` stays, with a message saying `com_b` needs it; its `package_id` points to `pkg_b` |
| 7 | Uninstall `pkg_b` next | everything removed, `lib_shared` included, because nothing needs it any more |
| 8 | Uninstall `lib_shared` in Manage while `com_a` is installed | refused, with the reason |
| 9 | Update `lib_shared` 1.1 → 1.2 via the update view | updated, **one** row, locked again afterwards |
| 10 | Update `lib_shared` to 2.0 via the update view while `com_a` needs ^1.0 | can't be refused: updated, with a warning that `com_a` is now unmet (a core gap) |
| 11 | Same via the install screen | refused as a breaking update |
| 12 | Install `com_c` on PHP without `sodium` | refused, names the PHP extension |
| 13 | Install the prototype on a site that already has `pkg_a` | edges and locks built from the installed manifests |
| 14 | Uninstall the prototype | all its locks removed; core extensions still locked |
| 15 | An extension with `<dependencies>` on a site *without* the prototype | installs as before (the element is ignored) |

## Risks and points to watch

- **Locking is visible to other tools.** Manage's "Locked" column shows Yes. An admin or another extension that resets `locked` takes away the protection; the prototype re-applies its locks after every install, update and uninstall, but not in between.
- **A failed prototype uninstall** could leave extensions locked. The uninstall routine unlocks first and drops the tables last. A CLI command or button to "unlock everything ours" is a cheap safety net.
- **Event order with other plugins** (for example Akeeba or Regular Labs installer plugins on `onInstallerBeforeInstaller`) may matter. Test with one or two common ones installed.
- **Reading child manifests from zips** must handle packages whose children are folders rather than zips, and very large packages.
