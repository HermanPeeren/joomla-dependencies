<?php

/**
 * @package     JoomlaDependencies
 * @license     GNU General Public License version 3 or later
 */

namespace JoomlaDependencies\Plugin\System\Dependencies\Dependency;

\defined('_JEXEC') or die;

/**
 * Decides whether an install may go ahead, by comparing the site before with the site as it would be after.
 */
final class Checker
{
    /**
     * Everything that stands in the way of installing $incoming (an extension or a whole package) on $site.
     *
     * - Requirements of the incoming extensions must be met by the site after the install; a package can meet
     *   its own children's requirements, and an extension the dependencies it bundles (installed only when the
     *   site doesn't meet the requirement already, as the extension's own script would).
     * - An incoming extension that installed extensions depend on must not be older than the one it replaces.
     * - An installed extension whose requirement on an incoming extension was met must still be met. A requirement
     *   that was already unmet before is not this install's fault, and doesn't block it.
     *
     * @return Problem[]
     */
    public function checkInstall(ExtensionManifest $incoming, SiteState $site): array
    {
        $items = $incoming->flatten();

        foreach ($incoming->flatten() as $item) {
            array_push($items, ...$this->bundledToInstall($item, $site));
        }

        $after = $site;

        foreach ($items as $item) {
            $after = $after->withInstalled($item);
        }

        $problems    = [];
        $incomingIds = [];

        foreach ($items as $item) {
            $incomingIds[$item->key->id()] = true;

            foreach ($item->requirements as $requirement) {
                $problem = $this->checkRequirement($item->name ?: (string) $item->key, $requirement, $after);

                if ($problem !== null) {
                    $problems[] = $problem;
                }
            }

            $problem = $this->checkDowngrade($item, $site);

            if ($problem !== null) {
                $problems[] = $problem;
            }
        }

        foreach ($site->extensions() as $installed) {
            if (isset($incomingIds[$installed->key->id()])) {
                continue;
            }

            foreach ($installed->requirements as $requirement) {
                if ($requirement->extension === null || !isset($incomingIds[$requirement->extension->id()])) {
                    continue;
                }

                $subject = $installed->name ?: (string) $installed->key;

                if ($this->checkRequirement($subject, $requirement, $site) !== null) {
                    continue;
                }

                $problem = $this->checkRequirement($subject, $requirement, $after);

                if ($problem !== null) {
                    $problems[] = new Problem(
                        ProblemType::BreaksDependent,
                        $problem->subject,
                        $requirement,
                        $problem->found
                    );
                }
            }
        }

        return $problems;
    }

    /**
     * The extensions $item carries itself (bundled="...") that its install script will install: those whose
     * requirement the site doesn't meet yet.
     *
     * @return ExtensionManifest[]
     */
    private function bundledToInstall(ExtensionManifest $item, SiteState $site): array
    {
        $toInstall = [];

        foreach ($item->bundled as $bundled) {
            foreach ($item->requirements as $requirement) {
                if ($requirement->targets($bundled->key) && $this->checkRequirement($item->name, $requirement, $site) !== null) {
                    array_push($toInstall, ...$bundled->flatten());
                    break;
                }
            }
        }

        return $toInstall;
    }

    /**
     * Whether one requirement is met on $site; null when it is.
     */
    public function checkRequirement(string $subject, Requirement $requirement, SiteState $site): ?Problem
    {
        $found = match ($requirement->kind) {
            RequirementKind::Extension    => $site->find($requirement->extension)?->version,
            RequirementKind::Joomla       => $site->joomlaVersion,
            RequirementKind::Php          => $site->phpVersion,
            RequirementKind::PhpExtension => $site->phpExtensions[$requirement->name] ?? null,
        };

        if ($found === null) {
            return new Problem(ProblemType::Missing, $subject, $requirement);
        }

        if ($requirement->constraint === '') {
            return null;
        }

        try {
            if (VersionConstraint::satisfies($found, $requirement->constraint)) {
                return null;
            }
        } catch (\UnexpectedValueException) {
            return new Problem(ProblemType::Unreadable, $subject, $requirement, $found);
        }

        return new Problem(ProblemType::WrongVersion, $subject, $requirement, $found);
    }

    /**
     * Only for extensions that another installed extension depends on: Joomla lets you install an older version
     * of anything, and the prototype doesn't change that for extensions nobody else needs.
     */
    private function checkDowngrade(ExtensionManifest $item, SiteState $site): ?Problem
    {
        $installed = $site->find($item->key);

        if ($installed === null || $site->dependentsOf($item->key) === []) {
            return null;
        }

        try {
            $older = VersionConstraint::isLower($item->version, $installed->version);
        } catch (\UnexpectedValueException) {
            // Without comparable versions there is nothing to say; Joomla itself doesn't compare either.
            return null;
        }

        if (!$older) {
            return null;
        }

        return new Problem(
            ProblemType::Downgrade,
            $item->name ?: (string) $item->key,
            Requirement::extension($item->key),
            $item->version,
            $installed->version
        );
    }
}
