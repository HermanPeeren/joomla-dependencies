# Draft: follow-up comment on joomla/rfc#9

*Draft, not posted. For [joomla/rfc#9](https://github.com/joomla/rfc/issues/9), "Composer Based Install and Dependency Management".*

---

This RFC stalled on "Composer-only vs. shared hosting". I'd like to propose a first step that needs no Composer on the site at all, and to show it working as a prototype: https://github.com/HermanPeeren/joomla-dependencies

**The problem it addresses.** Extensions that share something, most often a library, have no way to say so:

- a component installs fine and fails with a fatal error on its first screen when its library is missing;
- uninstalling one package removes a library another package still needs;
- a package carrying an older library overwrites a newer one;
- a library update runs the old library's uninstall routine, the cause of #35083 and #39360.

**The proposal: declarative dependencies in the manifest.**

```xml
<dependencies>
    <dependency type="library" element="yepr_gen" version="^0.19.1" bundled="library/lib_yepr_gen-0.19.1.zip" />
    <dependency type="component" element="com_example" version="&gt;=2.0" />
    <dependency type="joomla" version="^6.1" />
    <dependency type="php" version="&gt;=8.3" />
    <dependency type="php-extension" element="sodium" />
</dependencies>
```

Constraints use Composer's syntax (via `composer/semver`). `bundled` says the extension brings that dependency itself and installs it when needed, a common pattern that would otherwise make a pre-install check refuse the extension. Older Joomla versions ignore the element, and extensions without it behave as before.

**What the prototype shows.** It is a single system plugin, using only existing installer events and the `locked` column. It covers:

- refusing installs from the install screen whose dependencies aren't met, downgrades of shared extensions, and updates that would break an installed extension;
- keeping an extension others need, also when a package that carries it is uninstalled. The last package that needs it still takes it along;
- updating a locked shared library: the plugin unlocks it for the update and locks it again;
- warning afterwards where it can't refuse: the update view and the CLI;
- a "Dependencies" tab on the Install page and a `dependencies:status` CLI command.

15 test scenarios pass on Joomla 6.2: the CLI ones automatically, the rest in the browser. It also works with an existing family of extensions that share a library (Exten-gen and Gen-gen with `lib_yepr_gen`). Details are in [docs/results.md](https://github.com/HermanPeeren/joomla-dependencies/blob/main/docs/results.md).

**What it can't do without core**, which is the actual proposal:

1. `Installer` checks `<dependencies>` itself in `install()`, `update()` and `discover_install()`, so every route can refuse, not only the install screen.
2. An uninstall that is refused says who still needs the extension. Today Manage and the CLI only say "locked", and check that flag before any event fires.
3. A library is updated in place instead of uninstalled and reinstalled (#35083, #39360).
4. Packages and children become many-to-many, and a package doesn't uninstall a child another installed extension still needs.
5. Later: the update view and com_joomlaupdate's pre-update check use the same information.

None of this requires Composer, and all of it works on any shared host. A Composer-based layer for third-party PHP packages, which this RFC originally asked for, can be built on top later. An exploration found that Composer itself runs in-process from plain PHP files, without `proc_open` and in under 60 MB. It can use the same `composer.json`-style constraint syntax.

I'd welcome comments on the manifest format and on whether points 1–4 could go into a 6.x minor release.

Herman Peeren
