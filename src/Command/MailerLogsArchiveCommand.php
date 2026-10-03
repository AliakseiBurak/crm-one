<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\MailerLogArchiveService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:mailer-logs:archive',
    description: 'Архивирование журнала отправки писем по годам',
)]
class MailerLogsArchiveCommand extends Command
{
    public function __construct(
        private readonly MailerLogArchiveService $archiveService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $archived = $this->archiveService->archive();

        if ($archived > 0) {
            $output->writeln(\sprintf('<info>Файлов месяцев архивировано: %d</info>', $archived));
        } else {
            $output->writeln('<comment>Архивировать нечего.</comment>');
        }

        return Command::SUCCESS;
    }
}
