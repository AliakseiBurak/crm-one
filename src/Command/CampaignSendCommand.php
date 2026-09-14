<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\CampaignSendProcessor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:campaign:send', description: 'Фоновая отправка рассылок (outbox-паттерн)')]
class CampaignSendCommand extends Command
{
    public function __construct(
        private readonly CampaignSendProcessor $processor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'limit',
            null,
            InputOption::VALUE_REQUIRED,
            'Максимальное количество сообщений для отправки (по умолчанию из конфига mailing.batch_size)',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = $input->getOption('limit');
        $processed = $this->processor->process(null !== $limit ? (int) $limit : null);

        if (null === $processed) {
            $output->writeln('<comment>Команда уже выполняется другим экземпляром.</comment>');

            return Command::SUCCESS;
        }

        if ($processed > 0) {
            $output->writeln(\sprintf('<info>Обработано получателей: %d</info>', $processed));
        }

        return Command::SUCCESS;
    }
}
