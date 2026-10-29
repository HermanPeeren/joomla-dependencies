<?php

/**
 * @package     JoomlaDependencies
 * @license     GNU General Public License version 3 or later
 */

namespace JoomlaDependencies\Plugin\System\Dependencies\Console;

\defined('_JEXEC') or die;

use Joomla\Console\Command\AbstractCommand;
use JoomlaDependencies\Plugin\System\Dependencies\Dependency\Problem;
use JoomlaDependencies\Plugin\System\Dependencies\Site\DependencyService;
use JoomlaDependencies\Plugin\System\Dependencies\Site\Overview;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * php cli/joomla.php dependencies:status
 */
final class StatusCommand extends AbstractCommand
{
    protected static $defaultName = 'dependencies:status';

    public function __construct(private readonly DependencyService $service)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('List the declared dependencies of installed extensions, and what the Dependencies plugin keeps locked');
        $this->setHelp("<info>%command.name%</info> lists every <dependency> of every installed extension with its status, and the extensions the plugin locked because others need them.\nExits with 1 when a dependency is not met.");
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $overview = Overview::of($this->service);

        $io->title('Dependencies');

        if ($overview->requirements === []) {
            $io->text('No installed extension declares <dependencies>.');
        } else {
            $io->table(
                ['Extension', 'Needs', 'Status'],
                array_map(static fn (array $row): array => [
                    $row['extension'],
                    $row['requirement'],
                    $row['problem'] instanceof Problem ? '<error>' . $row['problem']->message() . '</error>' : 'met',
                ], $overview->requirements)
            );
        }

        $io->section('Locked because other extensions need them');

        if ($overview->locked === []) {
            $io->text('None.');
        } else {
            $io->table(
                ['Extension', 'Needed by'],
                array_map(static fn (array $row): array => [$row['extension'], implode(', ', $row['neededBy'])], $overview->locked)
            );
        }

        return $overview->unmetCount() === 0 ? 0 : 1;
    }
}
