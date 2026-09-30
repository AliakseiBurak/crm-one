<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dto;

use App\Dto\ImportRow;
use App\Dto\ImportRowCall;
use App\Dto\ImportRowContact;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `ImportRow::isEmptyRecord()` решает, что делать со строкой, у которой нет
 * названия: пропустить её и продвинуть счётчик либо остановить на ней импорт для
 * исправления. Ошибка в обе стороны стоит дорого — забытый пропуск заклинивает
 * импорт на одной и той же строке в начале каждого пакета, а лишняя остановка —
 * блокирует прогон на записи, исправить которую нечем.
 *
 * Контакт строку удерживает: это значение, которое администратор видит и которое
 * он может захотеть оставить, назвав организацию. Звонок — нет: он принадлежит
 * организации, поэтому на строке без неё ему некуда деваться, а фрагмент
 * «Актуальный курс» в описании названием не является. Поэтому пустое название при
 * непустых контактах останавливает импорт, а запись без названия и без контактов
 * пропускается, даже если в ней есть звонок.
 */
final class ImportRowTest extends TestCase
{
    /**
     * @param string|null $description
     */
    #[DataProvider('provideEmptyRecordDetectionCases')]
    public function testEmptyRecordDetection(
        string $name,
        ?string $description,
        bool $withContact,
        bool $withCall,
        bool $expected,
    ): void {
        $row = new ImportRow(rowNumber: 1, name: $name, description: $description);
        if ($withContact) {
            $row->contacts[] = new ImportRowContact(name: 'Иванов', phone: '+375 17 123-45-67');
        }
        if ($withCall) {
            $row->calls[] = new ImportRowCall(date: '10.05.2025', notes: 'Звонок');
        }

        self::assertSame($expected, $row->isEmptyRecord());
    }

    /**
     * Описание в набор условий не входит: «Актуальный курс» — это текст, а не
     * название, и строка, сохранившая только его, называться не может.
     *
     * @return iterable<string, array{string, string|null, bool, bool, bool}>
     */
    public static function provideEmptyRecordDetectionCases(): iterable
    {
        yield 'ничего нет' => ['', null, false, false, true];
        yield 'название из пробелов' => ['   ', null, false, false, true];
        yield 'только «Актуальный курс» в описании' => ['', 'Актуальный курс: Курс А', false, false, true];
        yield 'звонок без названия и контактов' => ['', null, false, true, true];
        yield 'звонок и «Актуальный курс» без названия' => ['', 'Актуальный курс: Курс А', false, true, true];
        yield 'контакт без названия' => ['', null, true, false, false];
        yield 'контакт и звонок без названия' => ['', null, true, true, false];
        yield 'название есть' => ['Нафтан', null, false, false, false];
        yield 'название и контакт' => ['Нафтан', null, true, false, false];
    }

    /**
     * Контакт удерживает строку при пустом названии: импорт останавливается на
     * ней, чтобы администратор назвал организацию и сохранил контакт.
     */
    public function testContactKeepsANamelessRowForCorrection(): void
    {
        $row = new ImportRow(rowNumber: 13, name: '');
        $row->contacts[] = new ImportRowContact(name: 'Петров');

        self::assertFalse($row->isEmptyRecord());
    }

    /**
     * Звонок строку не удерживает. В выгрузке такая запись есть — пустое
     * `Компания`, пустые `Контакты` и одна запись взаимодействия, — и остановка на
     * ней заклинила бы импорт на данных, которые исправить нечем.
     */
    public function testCallAloneDoesNotKeepANamelessRow(): void
    {
        $row = new ImportRow(rowNumber: 336, name: '');
        $row->calls[] = new ImportRowCall(date: '10.05.2025', notes: 'Позвонила, ждём КП');

        self::assertTrue($row->isEmptyRecord());
    }
}
