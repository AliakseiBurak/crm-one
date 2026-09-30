<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import;

use App\Service\Import\InteractionDateParser;
use App\Service\Import\InteractionEntry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InteractionDateParserTest extends TestCase
{
    private InteractionDateParser $parser;

    protected function setUp(): void
    {
        $this->parser = new InteractionDateParser();
    }

    #[DataProvider('provideAcceptedShapesCases')]
    public function testAcceptedShapes(string $input, string $expected): void
    {
        self::assertSame($expected, $this->parser->parseDate($input)?->format('d.m.Y'), $input);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideAcceptedShapesCases(): iterable
    {
        yield 'D.M.YYYY' => ['8.04.2025', '08.04.2025'];
        yield 'DD.MM.YYYY' => ['29.05.2026', '29.05.2026'];
        yield 'DD.MM.YY как 20YY' => ['21.10.25', '21.10.2025'];
        yield 'DD/MM/YYYY' => ['17/09/2025', '17.09.2025'];
        yield 'DD,MM.YYYY' => ['08,07.2025', '08.07.2025'];
        yield 'хвост подчёркивание' => ['05.11.2025_', '05.11.2025'];
        yield 'хвост точка' => ['05.11.2025.', '05.11.2025'];
    }

    public function testAcceptedDateLandsAtNoon(): void
    {
        self::assertSame('12:00', $this->parser->parseDate('25.08.2026')?->format('H:i'));
    }

    #[DataProvider('provideGroupsThatAreNotDatesAreRejectedCases')]
    public function testGroupsThatAreNotDatesAreRejected(string $group): void
    {
        self::assertNull($this->parser->parseDate($group), $group);
        self::assertFalse($this->parser->isDate($group), $group);
    }

    /**
     * Не дата — и вовсе не дата, и похожая на дату с негодным годом. Год не
     * восстанавливается, не усекается и не переносится из предыдущей записи.
     *
     * @return iterable<string, array{string}>
     */
    public static function provideGroupsThatAreNotDatesAreRejectedCases(): iterable
    {
        yield 'отдел кадров' => ['отдел кадров'];
        yield 'телефон' => ['+375171234567'];
        yield 'приятная девушка' => ['приятная девушка'];
        yield 'без года' => ['09.04'];
        yield 'трёхзначный год' => ['09.04.202'];
        yield 'пятизначный год' => ['09.04.20255'];
        yield 'несуществующий день' => ['31.02.2025'];
        yield 'несуществующий месяц' => ['09.13.2025'];
    }

    public function testTextWithoutAnyDateBecomesOneUndatedEntry(): void
    {
        $entries = $this->parser->parseEntries('Ни одной даты, только текст');

        self::assertCount(1, $entries);
        self::assertNull($entries[0]->date);
        self::assertSame('Ни одной даты, только текст', $entries[0]->notes());
    }

    public function testEmptyCellYieldsNoEntries(): void
    {
        self::assertSame([], $this->parser->parseEntries(''));
        self::assertSame([], $this->parser->parseEntries("  \n "));
    }

    public function testTextBeforeTheFirstDateIsAnUndatedEntry(): void
    {
        $entries = $this->parser->parseEntries("Текст до первой даты\n(12.11.2025) Итог");

        self::assertCount(2, $entries);
        self::assertNull($entries[0]->date);
        self::assertSame('Текст до первой даты', $entries[0]->notes());
        self::assertSame('12.11.2025', $entries[1]->date?->format('d.m.Y'));
        self::assertSame('Итог', $entries[1]->notes());
    }

    public function testEachDateTokenStartsItsOwnEntry(): void
    {
        $entries = $this->parser->parseEntries("(25.08.2026) Звонок 1\n(17.10.2025) Звонок 2");

        self::assertCount(2, $entries);
        self::assertSame('25.08.2026', $entries[0]->date?->format('d.m.Y'));
        self::assertSame('Звонок 1', $entries[0]->notes());
        self::assertSame('17.10.2025', $entries[1]->date?->format('d.m.Y'));
        self::assertSame('Звонок 2', $entries[1]->notes());
    }

    public function testEveryAcceptedShapeYieldsItsOwnDatedEntry(): void
    {
        $cell = implode("\n", [
            '(8.04.2025) Созвон',
            '(29.05.2026) Недозвон',
            '(21.10.25) Краткий год',
            '(17/09/2025) Косая черта',
            '(08,07.2025) Запятая',
            '(05.11.2025_) Подчёркивание',
            '(05.11.2025.) Точка',
        ]);

        $entries = $this->parser->parseEntries($cell);

        self::assertCount(7, $entries);
        self::assertSame(
            ['08.04.2025', '29.05.2026', '21.10.2025', '17.09.2025', '08.07.2025', '05.11.2025', '05.11.2025'],
            array_map(static fn(InteractionEntry $e): string => $e->date?->format('d.m.Y') ?? '', $entries),
        );
    }

    #[DataProvider('provideNonDateGroupStaysInTheNotesAndDatesNoCallCases')]
    public function testNonDateGroupStaysInTheNotesAndDatesNoCall(string $group): void
    {
        $cell = "(25.08.2026) Созвон\n({$group}) не поднимают";

        $entries = $this->parser->parseEntries($cell);

        $dated = array_values(array_filter($entries, static fn(InteractionEntry $e): bool => null !== $e->date));
        self::assertCount(1, $dated, $group);
        self::assertStringContainsString($group, $dated[0]->notes() ?? '', $group);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideNonDateGroupStaysInTheNotesAndDatesNoCallCases(): iterable
    {
        yield 'без года' => ['09.04'];
        yield 'трёхзначный год' => ['09.04.202'];
        yield 'пятизначный год' => ['09.04.20255'];
        yield 'не дата' => ['отдел кадров'];
    }

    public function testYearIsNotCarriedOverFromAPrecedingEntry(): void
    {
        $entries = $this->parser->parseEntries("(25.08.2026) Созвон\n(09.04.202) Созвон");

        self::assertCount(1, $entries);
        self::assertStringContainsString('(09.04.202) Созвон', $entries[0]->notes() ?? '');
    }

    public function testDateRangeIsOneEntryDatedWithTheFirstDate(): void
    {
        $cell = '(26.09.2025-06.10.2025- 13.10.2025) Недозвон снова';

        $entries = $this->parser->parseEntries($cell);

        self::assertCount(1, $entries);
        self::assertSame('26.09.2025', $entries[0]->date?->format('d.m.Y'));
        self::assertSame($cell, $entries[0]->notes());
    }

    public function testBareParenthesisedGroupIsNotAWholeEntryOnItsOwn(): void
    {
        // (+375171234567) не дата: остаётся текстом, отдельного звонка нет.
        $entries = $this->parser->parseEntries('(отдел кадров) не поднимают');

        self::assertCount(1, $entries);
        self::assertNull($entries[0]->date);
    }

    #[DataProvider('provideNextContactCases')]
    public function testNextContact(?string $expected, string $cell): void
    {
        self::assertSame($expected, $this->parser->parseNextContact($cell)?->format('d.m.Y'), $cell);
    }

    /**
     * @return iterable<string, array{string|null, string}>
     */
    public static function provideNextContactCases(): iterable
    {
        yield 'будущая дата' => ['08.06.2026', '08.06.2026'];
        yield 'прошедшая дата сохраняется как есть' => ['08.06.2020', '08.06.2020'];
        yield 'пусто' => [null, ''];
        yield 'дефис' => [null, '-'];
        yield 'подчёркивание' => [null, '_'];
        yield 'не актуально' => [null, 'не актуально'];
        yield 'нет' => [null, 'нет'];
        yield 'не дата' => [null, 'когда-нибудь'];
    }

    public function testPlaceholderDetection(): void
    {
        foreach (['-', '_', 'не актуально', 'нет', '  -  ', 'НЕТ', ''] as $cell) {
            self::assertTrue($this->parser->isPlaceholder($cell), $cell);
        }
        foreach (['08.06.2026', 'когда-нибудь'] as $cell) {
            self::assertFalse($this->parser->isPlaceholder($cell), $cell);
        }
    }
}
