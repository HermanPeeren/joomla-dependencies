<?php

/**
 * @package     JoomlaDependencies
 * @license     GNU General Public License version 3 or later
 */

namespace JoomlaDependencies\Plugin\System\Dependencies\Console;

\defined('_JEXEC') or die;

use Joomla\Console\Command\AbstractCommand;
use JoomlaDependencies\Plugin\System\Dependencies\Site\DependencyService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * php cli/joomla.php dependencies:unlock-all
 */
final class UnlockAllCommand extends AbstractCommand
{
    protected static $defaultName = 'dependencies:unlock-all';

    public function __construct(private readonly DependencyService $service)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Unlock every extension the Dependencies plugin locked');
        $this->setHelp("<info>%command.name%</info> sets locked = 0 on every extension the plugin locked, and forgets those locks. Extensions Joomla itself locks stay locked.\nThe next install, update or uninstall locks what is needed again; disable the plugin first to keep everything unlocked.");
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $count = $this->service->lockCount();

        $this->service->unlockAll();

        (new SymfonyStyle($input, $output))->success(\sprintf('Unlocked %d extension(s).', $count));

        return 0;
    }
}
