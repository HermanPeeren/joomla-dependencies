<?php

/**
 * @package     JoomlaDependencies
 * @license     GNU General Public License version 3 or later
 */

namespace JoomlaDependencies\Plugin\System\Dependencies\Site;

\defined('_JEXEC') or die;

use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use JoomlaDependencies\Plugin\System\Dependencies\Dependency\ExtensionKey;

/**
 * Reads and writes the few columns of #__extensions the prototype uses.
 */
final class ExtensionTable
{
    private const COLUMNS = ['extension_id', 'package_id', 'name', 'type', 'element', 'folder', 'client_id', 'locked', 'manifest_cache'];

    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * Installed extensions; rows that were only discovered, not installed, are left out.
     *
     * @return object[]
     */
    public function all(): array
    {
        $query = $this->db->createQuery()
            ->select($this->db->quoteName(self::COLUMNS))
            ->from($this->db->quoteName('#__extensions'))
            ->where($this->db->quoteName('state') . ' <> -1');

        return $this->db->setQuery($query)->loadObjectList();
    }

    public function byId(int $id): ?object
    {
        $query = $this->db->createQuery()
            ->select($this->db->quoteName(self::COLUMNS))
            ->from($this->db->quoteName('#__extensions'))
            ->where($this->db->quoteName('extension_id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        return $this->db->setQuery($query)->loadObject() ?: null;
    }

    /**
     * Every row for this key: normally one, two after a botched library update.
     *
     * @return object[]
     */
    public function byKey(ExtensionKey $key): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (object $row): bool => self::keyOf($row)?->equals($key) ?? false
        ));
    }

    /**
     * @param  int[]  $ids
     */
    public function setLocked(array $ids, bool $locked): void
    {
        if ($ids === []) {
            return;
        }

        $query = $this->db->createQuery()
            ->update($this->db->quoteName('#__extensions'))
            ->set($this->db->quoteName('locked') . ' = ' . (int) $locked)
            ->whereIn($this->db->quoteName('extension_id'), array_map('intval', $ids));

        $this->db->setQuery($query)->execute();
    }

    public function setPackageId(int $id, int $packageId): void
    {
        $query = $this->db->createQuery()
            ->update($this->db->quoteName('#__extensions'))
            ->set($this->db->quoteName('package_id') . ' = :package')
            ->where($this->db->quoteName('extension_id') . ' = :id')
            ->bind(':package', $packageId, ParameterType::INTEGER)
            ->bind(':id', $id, ParameterType::INTEGER);

        $this->db->setQuery($query)->execute();
    }

    /**
     * @param  int  $packageId
     *
     * @return object[]
     */
    public function childrenOf(int $packageId): array
    {
        return array_values(array_filter($this->all(), static fn (object $row): bool => (int) $row->package_id === $packageId));
    }

    /**
     * The row's key, or null for a row the prototype can't identify (an unknown type or client).
     */
    public static function keyOf(object $row): ?ExtensionKey
    {
        try {
            return ExtensionKey::of($row->type, $row->element, (string) $row->folder, (int) $row->client_id);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    public static function versionOf(object $row): string
    {
        $cache = json_decode((string) $row->manifest_cache, true);

        return \is_array($cache) ? (string) ($cache['version'] ?? '') : '';
    }
}
