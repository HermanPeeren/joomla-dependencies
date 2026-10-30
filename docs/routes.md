# Routes towards dependency management in Joomla

An exploration of how Joomla could manage dependencies between extensions compared five routes, labelled A to E. This document summarises them, says what was checked afterwards, and explains why this repository prototypes route B first. If other routes are prototyped later, they can refer to the same labels.

## The problem

Joomla uses Composer in its own repository; the result ships frozen in `libraries/vendor`. Extensions can't add to that, and the installer has no notion of "extension X needs library Y, version ^2.1". So:

- **Version clashes:** two extensions ship the same PHP library in different versions; PHP loads one class per name per request, so one of them breaks.
- **Uninstall breakage:** a shared `library` that is a child of several packages is removed by the first package that is uninstalled.
- **Downgrades:** a package carrying an older copy of a library installs it over a newer one.
- **No pre-install check:** a component whose library is missing installs cleanly and fails with a fatal error on its first screen.
- **Core updates:** a Joomla update changes `libraries/vendor`, and nothing checks the extensions that rely on it.

Composer itself is not the obvious answer: Joomla has to keep working on shared hosting, where Composer is often hard or impossible to run.

## How other PHP CMSs do it

| Approach | Who | Works on restrictive shared hosting? | Shared versions? |
|---|---|---|---|
| Composer from the command line only | Drupal, TYPO3 (Composer mode), Magento | no | yes |
| Composer from the admin, run as a separate process | Drupal Package Manager, Craft, Contao Manager | only with a PHP CLI binary and `proc_open` | yes |
| Composer from the admin, dependencies resolved in the cloud | Contao Manager (optional Resolver Cloud) | lowers memory needs; still needs `proc_open` | yes |
| Own manager, dependencies between extensions only | TYPO3 classic mode, Grav, WordPress 6.5 (`Requires Plugins`, without versions) | yes | extensions only, not PHP libraries |
| Every extension bundles its own copy, with prefixed namespaces | WordPress (PHP-Scoper, Strauss), Nextcloud | yes | no |

No CMS has full dependency management that works on every shared host.

## The five routes

Every route has to answer the same four questions:
- What is a dependency: another Joomla extension, or a third-party PHP package?
- Does the site get one version of each, or does each extension get its own copy?
- Where does resolution run: on the site, on a service, or at the developer's build?
- How are the versions Joomla core ships in `libraries/vendor` treated? As fixed, already-provided packages.

### A: conventions and documentation, no core change

Document what developers can already do:
- **Private dependencies:** prefix their namespaces with PHP-Scoper or Strauss.
- **Truly shared code:** a `library` extension with its own update server. Each consumer installs it from its own `postflight()` when it is missing or too old. An uninstall guard in the library's own script refuses while consumers are installed.

*Backwards compatibility:* complete. *Weakness:* every developer must get it right alone, and nothing checks versions across extensions.

### B: declarative dependencies in the manifest, with reference counting (small core change)

A `<dependencies>` element in the extension manifest. The installer then:
- checks it before installing;
- records who needs what;
- refuses to uninstall what others still need, including when a package carries it;
- refuses downgrades of shared extensions;
- updates libraries in place.

*Backwards compatibility:* very good. Older Joomla versions ignore the element, and extensions without it behave as before. *Scope:* dependencies between Joomla extensions, plus the Joomla, PHP and PHP-extension versions. Third-party PHP packages still have to be wrapped in a library extension or prefixed.

### C: a Joomla package registry with its own resolver (larger core change)

Builds on B, and makes third-party PHP packages first-class without running Composer on the site:
- **Repository:** a Joomla-run repository of vetted packages, in Composer's repository format.
- **Resolver:** a simple one, because a site has one version of each package: take the highest version every constraint accepts, or refuse and name the conflict.
- **Install:** packages unpack into their own folder under `libraries/`, with a generated autoloader.
- **Integrity:** signed metadata (TUF, which Joomla already uses for core updates).

*Backwards compatibility:* a new subsystem next to the existing one. Extensions that bundle their own copies keep working; the registry can detect such copies if extensions declare them.

### D: Composer embedded in core, for a second, extension-only vendor tree (larger core change)

Joomla ships `composer/composer` as plain PHP:
- **A separate project:** a second root `composer.json` belongs to extensions only.
- **Core versions fixed:** a generated meta-package `provide`s the exact versions in core's `libraries/vendor`.
- **Install:** an extension with a `composer.json` is added to that project, in steps and staged so the site never has a half-finished state.

*Backwards compatibility:* opt-in per extension. *Risks:* Composer runs inside Joomla's process; packages from Packagist are not vetted; Composer's internal API carries no stability promise.

### E: resolve elsewhere, install on the site

A variant for C or D:
- **Where resolution runs:** on a Joomla-run service, or at the developer's build.
- **What the site does:** it only downloads the zips in the resulting lock file, checks their hashes and unpacks them.

This is the only route where the host needs neither much memory nor `proc_open`. A service sees which extensions a site runs, so it should be opt-in.

## What was checked afterwards

| Question | Answer |
|---|---|
| Can Composer run on a host without `proc_open` or a PHP CLI binary? | Yes, for packages from Packagist as zips. Composer 2.8 run in-process from plain PHP files inside a web request installed 15 packages correctly. That was with `proc_open`, `exec` and the other process functions disabled, a 128 MB memory limit and `open_basedir` set. It peaked at 6–17 MiB. What breaks is anything that needs git or another external program. |
| Is memory the problem people say it is? | Not for Composer 2. Resolving Laravel, Symfony and PhpSpreadsheet together (69 packages) peaked at 56 MiB. |
| Do Composer's own dependencies fit Joomla's? | Yes. Adding `composer/composer` 2.11 to Joomla 6.2-dev needs one minor bump (`composer/ca-bundle` 1.5 → 1.6), 10 new packages, and 8 packages Joomla now uses only for development; about 4.4 MB. |
| Which installer events can a plugin use to refuse something? | Only the install screen can refuse an install; nothing can refuse an uninstall, but the `locked` column in `#__extensions` stops every uninstall route. This made route B prototypable as a plugin. |
| Is there earlier work? | [joomla/rfc#9](https://github.com/joomla/rfc/issues/9), "Composer Based Install and Dependency Management", open since 2020. It stalled on "Composer-only against shared hosting". |

These checks make D more realistic than it first looked. C with E is still the more robust choice for third-party PHP packages.

## Why route B first

- **Every other route needs it.** C, D and E are about *third-party PHP packages*. All of them still need the installer to know which extension needs which, to refuse what would break, and to keep what others need. That is B.
- **It fixes the breakage that actually happens.** Missing libraries, shared libraries removed by a package, downgrades, and library updates that run the old uninstall routine are all problems between *Joomla extensions*, not with Packagist.
- **It works on every host.** No Composer, no `proc_open`, no extra memory, no service.
- **It is backwards compatible and small.** A manifest element older versions ignore, a table, and checks in the installer.
- **It could be built without touching core.** The `locked` column and the existing installer events were enough for a plugin. Building it shows exactly which core changes are needed rather than guessing.

Route A stays useful next to it, as guidance for developers until B is in core.

## Status

| Route | Status |
|---|---|
| A | described; part of it is what the generator family (`lib_yepr_gen`) already does |
| **B** | **prototyped in this repository**: [plan.md](plan.md), [results.md](results.md) |
| C | not explored further |
| D | Composer measured without `proc_open` and against Joomla's dependencies (see above); not prototyped |
| E | not explored further |
