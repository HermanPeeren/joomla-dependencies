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
 * php cli/joomla.php dependencies:sync
 */
final class SyncCommand extends AbstractCommand
{
    protected static $defaultName = 'dependencies:sync';

    public function __construct(private readonly DependencyService $service)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Re-read the manifests of all installed extensions and re-apply the locks');
        $this->setHelp("<info>%command.name%</info> rebuilds the dependency table from the installed manifests, as the plugin's install does.\nUse it after extensions were changed while the plugin was disabled, or by hand.");
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        foreach ($this->service->sync() as $warning) {
            $io->warning($warning);
        }

        $io->success(\sprintf('Dependency table rebuilt; %d extension(s) locked because others need them.', $this->service->lockCount()));

        return 0;
    }
}
