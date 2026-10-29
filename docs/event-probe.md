# Event probe: findings

Phase 0, 29 October 2026, Joomla 6.2.0. `pkg_depprobe` installs a plugin in each of the `system`, `installer` and `extension` groups. Each one logs every installer event it receives, with its arguments, to `administrator/logs/depprobe.log`. All runs below started from a fresh site (`tools/install-joomla.sh --force`).

## Results

### 1. A system plugin receives every event, on every route, first

| Route | `system` | `installer` | `extension` |
|---|---|---|---|
| CLI `extension:install` / `extension:remove` | all `onExtension…` events | nothing | all `onExtension…` events |
| Install from URL in com_installer | all `onInstaller…` and `onExtension…` events | the same, once com_installer has loaded the group | `onExtension…` events, and `onInstallerAfterInstaller` once loaded |

On the web the system plugin always comes first. All plugins listen on the application's dispatcher, so once a group is loaded its plugins also receive events meant for the other group.

**Decision:** the prototype becomes **one system plugin** instead of an installer plugin plus an extension plugin (plan, "How it works"). It can still refuse an install from the install screen, because a system plugin's `false` from `onInstallerBeforeInstaller` counts like any other listener's. That will be confirmed in phase 3.

### 2. Updating a library gives it a new `extension_id` and drops its `package_id`

Installing `lib_shared` 1.2.0 over 1.1.0 (which `pkg_a` had installed):

```
onExtensionBeforeInstall   type=library manifest(shared 1.2.0)
onExtensionBeforeUninstall eid=256
onExtensionAfterUninstall  eid=256 removed=true  installer(packageUninstall=yes)
onExtensionAfterInstall    eid=259
```

Afterwards the library is row 259 with `package_id = 0`: it no longer belongs to `pkg_a`. Installing a package that carries the library gives it a new id again, and that package becomes the owner.

**Consequences:**
- The dependency table must identify extensions by type + element (+ folder/client), never by `extension_id`.
- `onExtensionBeforeInstall` for a library, followed by `onExtensionBeforeUninstall` of the installed row with the same element, is how the plugin recognises "this uninstall is part of an update".

### 3. A locked library blocks its own update, silently

With `locked = 1` on `lib_shared` 1.2.0, installing 2.0.0:

```
onExtensionBeforeInstall   type=library manifest(shared 2.0.0)
onExtensionBeforeUninstall eid=259
onExtensionAfterUninstall  eid=259 removed=false
onExtensionAfterInstall    eid=260
```

The CLI reports "Extension installed successfully". Two rows are left: 259 (1.2.0, locked) and 260 (2.0.0); the files on disk are 2.0.0. This is the generator-core 0.19.1 symptom again.

**Consequence:** the unlock/relock step in the plan (flow 3) is essential, and `onExtensionBeforeInstall` comes early enough to do it.

### 4. `locked` protects a shared library during a package uninstall

`pkg_a` and `pkg_b` both carry `lib_shared`. With the library locked, `extension:remove` of `pkg_a`:

```
onExtensionBeforeUninstall eid=258 (pkg_a)
onExtensionBeforeUninstall eid=259 (shared)
onExtensionAfterUninstall  eid=259 removed=false
onExtensionBeforeUninstall eid=257 (com_a)
onExtensionAfterUninstall  eid=257 removed=true
onExtensionAfterUninstall  eid=258 removed=true
```

`pkg_a` and `com_a` are removed. `lib_shared` stays, still owned by `pkg_b`, with its files intact. The CLI only says "Extension removed!"; it doesn't mention the child it kept, so the plugin's own message (flow 4) is needed.

### 5. Don't call `getManifest()` on the event's installer during an uninstall

The first version of the probe called `$installer->getManifest()` in `onExtensionAfterUninstall`. After a refused uninstall the installer has no manifest path, so `getManifest()` → `findManifest()` → `Path::clean('')` throws, and the whole install aborts. The prototype must read the public `$installer->manifest` property, or the manifest from disk.

## Not covered in phase 0

- **The update view** (System → Update → Extensions) needs an update server for the fixtures. It calls the same `Installer::update()` as tested here, so the same `onExtension…` events are expected. To be checked in phase 2 with a local update server.
- **Uninstall from Manage in the browser:** `ManageModel::remove()` checks `locked` itself and shows `COM_INSTALLER_UNINSTALL_ERROR_LOCKED_EXTENSION`. That comes from reading the code; it hasn't been clicked through.
- **Discover install.**
