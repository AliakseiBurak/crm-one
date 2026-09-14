<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\CampaignSendCommand;
use App\Service\CampaignSendProcessor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CampaignSendCommandTest extends TestCase
{
    public function testPassesLimitToProcessor(): void
    {
        $processor = $this->createMock(CampaignSendProcessor::class);
        $processor->expects(self::once())->method('process')->with(20)->willReturn(3);

        $tester = $this->tester($processor);
        self::assertSame(Command::SUCCESS, $tester->execute(['--limit' => '20']));
        self::assertStringContainsString('Обработано получателей: 3', $tester->getDisplay());
    }

    public function testUsesConfiguredBatchWhenLimitOmitted(): void
    {
        $processor = $this->createMock(CampaignSendProcessor::class);
        $processor->expects(self::once())->method('process')->with(null)->willReturn(0);

        $tester = $this->tester($processor);
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame('', $tester->getDisplay());
    }

    public function testReportsBusyLock(): void
    {
        $processor = $this->createMock(CampaignSendProcessor::class);
        $processor->method('process')->willReturn(null);

        $tester = $this->tester($processor);
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('уже выполняется', $tester->getDisplay());
    }

    private function tester(CampaignSendProcessor $processor): CommandTester
    {
        $command = new CampaignSendCommand($processor);
        $command->setName('app:campaign:send');

        return new CommandTester($command);
    }
}
