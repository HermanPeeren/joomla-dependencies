<?php

/**
 * @package     JoomlaDependencies
 * @license     GNU General Public License version 3 or later
 */

namespace JoomlaDependencies\Plugin\System\Dependencies\Dependency;

\defined('_JEXEC') or die;

/**
 * Identifies an extension the way Joomla's installer does: type and element, plus the folder for plugins and the
 * client for modules, templates and languages.
 *
 * Never the extension_id: a library update gives the library a new one.
 */
final readonly class ExtensionKey
{
    public const TYPES = ['component', 'file', 'language', 'library', 'module', 'package', 'plugin', 'template'];

    private const CLIENT_TYPES = ['language', 'module', 'template'];

    private function __construct(
        public string $type,
        public string $element,
        public string $folder,
        public ?int $clientId
    ) {
    }

    /**
     * @param  string           $type     One of self::TYPES
     * @param  string           $element  As in #__extensions.element, e.g. com_foo, mod_foo, pkg_foo, foo for a library
     * @param  string           $folder   The plugin group; ignored for other types
     * @param  string|int|null  $client   'site', 'administrator', 0 or 1; only for modules, templates and languages
     *
     * @throws \InvalidArgumentException
     */
    public static function of(string $type, string $element, string $folder = '', string|int|null $client = null): self
    {
        $type = strtolower(trim($type));

        if (!\in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown extension type "%s"', $type));
        }

        $element = trim($element);

        if ($element === '') {
            throw new \InvalidArgumentException(\sprintf('A %s needs an element', $type));
        }

        if ($type === 'plugin' && trim($folder) === '') {
            throw new \InvalidArgumentException(\sprintf('Plugin "%s" needs a folder (its group)', $element));
        }

        return new self(
            $type,
            $element,
            $type === 'plugin' ? strtolower(trim($folder)) : '',
            \in_array($type, self::CLIENT_TYPES, true) ? self::clientId($client) : null
        );
    }

    /**
     * Two keys are the same extension when their ids are equal. Joomla compares elements case-insensitively.
     */
    public function id(): string
    {
        return implode(':', [$this->type, strtolower($this->element), $this->folder, $this->clientId ?? '']);
    }

    /**
     * The key back from id(); the element comes back in lower case.
     *
     * @throws \InvalidArgumentException
     */
    public static function fromId(string $id): self
    {
        $parts = explode(':', $id);

        if (\count($parts) !== 4) {
            throw new \InvalidArgumentException(\sprintf('Not an extension key id: "%s"', $id));
        }

        [$type, $element, $folder, $client] = $parts;

        return self::of($type, $element, $folder, $client === '' ? null : (int) $client);
    }

    public function equals(self $other): bool
    {
        return $this->id() === $other->id();
    }

    /**
     * For messages: "library shared", "plugin system/foo", "module mod_foo (site)".
     */
    public function __toString(): string
    {
        $name = $this->type === 'plugin' ? $this->folder . '/' . $this->element : $this->element;

        if ($this->clientId !== null) {
            $name .= match ($this->clientId) {
                1       => ' (administrator)',
                3       => ' (api)',
                default => ' (site)',
            };
        }

        return $this->type . ' ' . $name;
    }

    /**
     * Joomla's client ids: 0 site, 1 administrator, 3 api (language packs have an api part).
     */
    private static function clientId(string|int|null $client): int
    {
        return match (\is_string($client) ? strtolower(trim($client)) : $client) {
            null, '', 0, '0', 'site'         => 0,
            1, '1', 'administrator', 'admin' => 1,
            3, '3', 'api'                    => 3,
            default => throw new \InvalidArgumentException(\sprintf('Unknown client "%s"', $client)),
        };
    }
}
