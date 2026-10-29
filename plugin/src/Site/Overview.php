<?php

/**
 * @package     JoomlaDependencies
 * @license     GNU General Public License version 3 or later
 */

namespace JoomlaDependencies\Plugin\System\Dependencies\Site;

\defined('_JEXEC') or die;

use JoomlaDependencies\Plugin\System\Dependencies\Dependency\ExtensionKey;
use JoomlaDependencies\Plugin\System\Dependencies\Dependency\Problem;

/**
 * What the site's dependencies look like now: for the installer tab and `dependencies:status`.
 */
final class Overview
{
    /**
     * @param  array<int, array{extension: string, requirement: string, problem: ?Problem}>  $requirements
     * @param  array<int, array{extension: string, neededBy: string[]}>                      $locked
     */
    private function __construct(public readonly array $requirements, public readonly array $locked)
    {
    }

    public static function of(DependencyService $service): self
    {
        $site         = $service->siteState();
        $requirements = [];

        foreach ($site->extensions() as $extension) {
            $name = $extension->name ?: (string) $extension->key;

            foreach ($extension->requirements as $requirement) {
                $requirements[] = [
                    'extension'   => $name,
                    'requirement' => (string) $requirement,
                    'problem'     => $service->checker()->checkRequirement($name, $requirement, $site),
                ];
            }
        }

        $locked = [];

        foreach ($service->locks() as $id) {
            $key       = ExtensionKey::fromId($id);
            $installed = $site->find($key);

            $locked[] = [
                'extension' => $installed?->name ?: (string) $key,
                'neededBy'  => array_map(
                    static fn (array $pair): string => $pair[0]->name ?: (string) $pair[0]->key,
                    $site->dependentsOf($key)
                ),
            ];
        }

        usort($requirements, static fn (array $a, array $b): int => [$a['problem'] === null, $a['extension']] <=> [$b['problem'] === null, $b['extension']]);

        return new self($requirements, $locked);
    }

    public function unmetCount(): int
    {
        return \count(array_filter($this->requirements, static fn (array $row): bool => $row['problem'] !== null));
    }
}
