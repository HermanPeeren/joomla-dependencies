<?php

/**
 * @package     JoomlaDependencies
 * @license     GNU General Public License version 3 or later
 */

namespace JoomlaDependencies\Plugin\System\Dependencies\Site;

\defined('_JEXEC') or die;

use Joomla\Database\DatabaseInterface;
use JoomlaDependencies\Plugin\System\Dependencies\Dependency\ExtensionKey;
use JoomlaDependencies\Plugin\System\Dependencies\Dependency\Requirement;
use JoomlaDependencies\Plugin\System\Dependencies\Dependency\RequirementKind;

/**
 * The prototype's two tables: who requires what (#__dependencies_edges), and which extensions the prototype
 * locked (#__dependencies_locks). Extensions are stored by ExtensionKey::id(), never by extension_id.
 */
final class DependencyStore
{
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * @param  Requirement[]  $requirements
     */
    public function replace(ExtensionKey $dependent, array $requirements): void
    {
        $this->forget($dependent);

        foreach ($requirements as $requirement) {
            $row = (object) [
                'dependent'          => $dependent->id(),
                'kind'               => $requirement->kind->value,
                'target'             => $requirement->extension?->id() ?? $requirement->name,
                'version_constraint' => $requirement->constraint,
            ];

            $this->db->insertObject('#__dependencies_edges', $row);
        }
    }

    public function forget(ExtensionKey $dependent): void
    {
        $id    = $dependent->id();
        $query = $this->db->createQuery()
            ->delete($this->db->quoteName('#__dependencies_edges'))
            ->where($this->db->quoteName('dependent') . ' = :dependent')
            ->bind(':dependent', $id);

        $this->db->setQuery($query)->execute();
    }

    public function clear(): void
    {
        $this->db->truncateTable('#__dependencies_edges');
    }

    /**
     * @return array<string, Requirement[]>  Dependent key id => its requirements
     */
    public function all(): array
    {
        $query = $this->db->createQuery()
            ->select($this->db->quoteName(['dependent', 'kind', 'target', 'version_constraint']))
            ->from($this->db->quoteName('#__dependencies_edges'))
            ->order($this->db->quoteName('id'));

        $requirements = [];

        foreach ($this->db->setQuery($query)->loadObjectList() as $row) {
            $requirement = match (RequirementKind::tryFrom($row->kind)) {
                RequirementKind::Extension    => Requirement::extension(ExtensionKey::fromId($row->target), $row->version_constraint),
                RequirementKind::Joomla       => Requirement::joomla($row->version_constraint),
                RequirementKind::Php          => Requirement::php($row->version_constraint),
                RequirementKind::PhpExtension => Requirement::phpExtension($row->target, $row->version_constraint),
                null                          => null,
            };

            if ($requirement !== null) {
                $requirements[$row->dependent][] = $requirement;
            }
        }

        return $requirements;
    }

    /**
     * @return string[]  Key ids of the extensions this prototype locked
     */
    public function locks(): array
    {
        $query = $this->db->createQuery()
            ->select($this->db->quoteName('extension'))
            ->from($this->db->quoteName('#__dependencies_locks'));

        return $this->db->setQuery($query)->loadColumn();
    }

    public function addLock(ExtensionKey $key): void
    {
        if (\in_array($key->id(), $this->locks(), true)) {
            return;
        }

        $row = (object) ['extension' => $key->id()];
        $this->db->insertObject('#__dependencies_locks', $row);
    }

    public function removeLock(string $keyId): void
    {
        $query = $this->db->createQuery()
            ->delete($this->db->quoteName('#__dependencies_locks'))
            ->where($this->db->quoteName('extension') . ' = :extension')
            ->bind(':extension', $keyId);

        $this->db->setQuery($query)->execute();
    }
}
