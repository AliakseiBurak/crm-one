<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\EmailAddressMasker;
use Monolog\Formatter\LineFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Маскирование адресов в журнале отправки (change email-send-logging, D3):
 * один адрес, список, адреса внутри произвольного текста и многострочного
 * ответа MTA. Локальная часть адреса не должна встречаться в записи нигде.
 */
final class EmailAddressMaskerTest extends TestCase
{
    private EmailAddressMasker $masker;

    protected function setUp(): void
    {
        $this->masker = new EmailAddressMasker();
    }

    public function testKeepsOnlyFirstCharacterOfLocalPartAndDomain(): void
    {
        self::assertSame('c***@example.ru', $this->masker->mask('client@example.ru'));
    }

    public function testMasksSingleCharacterLocalPart(): void
    {
        self::assertSame('a***@example.ru', $this->masker->mask('anna@example.ru'));
    }

    public function testMasksLongLocalPart(): void
    {
        self::assertSame(
            'v***@example.ru',
            $this->masker->mask('very.long.local.part.name.with.dots@example.ru'),
        );
    }

    public function testDifferentAddressesOfSameDomainGetDifferentMasks(): void
    {
        self::assertSame('a***@example.ru', $this->masker->mask('anna@example.ru'));
        self::assertSame('b***@example.ru', $this->masker->mask('bob@example.ru'));
    }

    public function testMasksEmptyLocalPartWithoutLeakingDomainOnlyTail(): void
    {
        self::assertSame('***@example.ru', $this->masker->mask('@example.ru'));
    }

    public function testMasksAddressLikeValueWithoutAt(): void
    {
        // Без «@» адрес не собирается, но локальная часть не должна утечь.
        self::assertSame('***', $this->masker->mask('client.example.ru'));
        self::assertSame('***', $this->masker->mask('client+tag'));
    }

    public function testKeepsFreeTextWithoutAddressCharactersAsIs(): void
    {
        self::assertSame('Recipient address rejected', $this->masker->mask('Recipient address rejected'));
        self::assertSame('', $this->masker->mask(''));
    }

    public function testMasksEveryAddressOfList(): void
    {
        self::assertSame(
            ['a***@example.ru', 's***@example.org'],
            $this->masker->maskList(['anna@example.ru', 'sales@example.org']),
        );
    }

    public function testMasksEmptyList(): void
    {
        self::assertSame([], $this->masker->maskList([]));
    }

    public function testMasksAddressInsideText(): void
    {
        self::assertSame(
            '550 5.1.1 <c***@example.ru>: Recipient address rejected: User unknown',
            $this->masker->maskInText('550 5.1.1 <client@example.ru>: Recipient address rejected: User unknown'),
        );
    }

    public function testKeepsTextWithoutAddressesUnchanged(): void
    {
        $text = 'Connection timed out after 30 seconds';

        self::assertSame($text, $this->masker->maskInText($text));
    }

    /**
     * Реальные ответы MTA: маскирование обязано закрыть адрес получателя на
     * каждом из них, а не на одном примере.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function provideLocalPartNeverSurvivesSmtpErrorCases(): iterable
    {
        yield 'Postfix 550 в угловых скобках' => [
            'got code "550" 550 5.1.1 <client@example.ru>: Recipient address rejected: User unknown',
        ];

        yield 'многострочный ответ Postfix' => [
            "got code \"550\"\n550-5.1.1 The email account that you tried to reach\n"
            . ' 550 5.1.1 <client@example.ru> does not exist',
        ];

        yield 'Exim 550' => [
            'got code "550" client@example.ru: Rejected: address does not exist',
        ];

        yield 'Gmail 550' => [
            'got code "550" 550-5.2.1 The email account that you tried to reach does not exist. '
            . 'Try double-checking the recipient address. 550 5.2.1 <client@example.ru> '
            . 'sender denied',
        ];

        yield 'Gmail 421' => [
            'got code "421" 4.7.0 <client@example.ru> Temporary failure (for example, '
            . 'too many connections)',
        ];

        yield 'таймаут без адреса' => ['Connection timed out'];

        yield 'отказ в соединении' => ['Connection refused'];

        yield 'ошибка аутентификации 535' => [
            'Failed to authenticate on SMTP server with username "user@b2b-crm.local": '
            . '535 5.7.8 Authentication credentials invalid',
        ];
    }

    #[DataProvider('provideLocalPartNeverSurvivesSmtpErrorCases')]
    public function testLocalPartNeverSurvivesSmtpError(string $error): void
    {
        $cleaned = $this->masker->maskInText($error);

        self::assertStringNotContainsString('client', $cleaned);
        if (str_contains($error, 'client@example.ru')) {
            // Домен сохраняется: по нему записи группируются по провайдеру.
            self::assertStringContainsString('example.ru', $cleaned);
        }
    }

    public function testMasksEveryAddressOfMultilineResponse(): void
    {
        $error = "550-5.1.1 <client@example.ru>: Recipient address rejected\n"
            . '550 5.1.1 <other@example.ru> does not exist';

        $cleaned = $this->masker->maskInText($error);

        self::assertSame(
            "550-5.1.1 <c***@example.ru>: Recipient address rejected\n"
            . '550 5.1.1 <o***@example.ru> does not exist',
            $cleaned,
        );
    }

    public function testMultilineErrorStaysSingleLogRecordLine(): void
    {
        // Тот же путь и те же аргументы форматтера, что у обработчика канала
        // `mailer` в приложении: контекст проходит через convertToString и
        // replaceNewlines, поэтому переводы строк не должны разорвать запись.
        $formatter = new LineFormatter(
            "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
            'Y-m-d\TH:i:sP',
            false,
            false,
        );

        $record = new LogRecord(
            new \DateTimeImmutable('2026-03-01 12:00:00'),
            'mailer',
            Level::Warning,
            'Campaign send failed',
            [
                'recipient' => $this->masker->mask('client@example.ru'),
                'error' => $this->masker->maskInText(
                    "got code \"550\"\n550-5.1.1 The email account that you tried to reach\n"
                    . ' 550 5.1.1 <client@example.ru> does not exist',
                ),
            ],
        );

        $line = $formatter->format($record);

        self::assertStringNotContainsString("\n", substr($line, 0, -1));
        self::assertStringContainsString('c***@example.ru', $line);
        self::assertStringNotContainsString('client@example.ru', $line);
    }
}
