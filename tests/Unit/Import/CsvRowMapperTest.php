<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import;

use App\Dto\ContactData;
use App\Service\Import\CsvParser;
use App\Service\Import\CsvRowMapper;
use App\Service\Import\InteractionDateParser;
use App\Service\Import\PhoneNormalizer;
use App\Tests\Fixtures\ImportCsvFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CsvRowMapperTest extends TestCase
{
    private CsvRowMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new CsvRowMapper(new InteractionDateParser(), new PhoneNormalizer());
    }

    public function testMapsOrganizationName(): void
    {
        $org = $this->mapper->map($this->record('Нафтан', interactions: '(25.08.2026) Пока потребности нет'));

        self::assertSame('Нафтан', $org->name);
    }

    public function testActualCourseIsAppendedToDescriptionAsLabelledFragment(): void
    {
        $org = $this->mapper->map($this->record('Нафтан', course: 'Курс по переговорам'));

        self::assertSame('Актуальный курс: Курс по переговорам', $org->description);
    }

    public function testEmptyActualCourseLeavesDescriptionEmpty(): void
    {
        self::assertNull($this->mapper->map($this->record('Нафтан'))->description);
    }

    public function testCurrentStateColumnIsNotReadAtAll(): void
    {
        $org = $this->mapper->map($this->record(
            'Нафтан',
            course: 'Курс А',
            interactions: '(25.08.2026) Созвон',
            contacts: 'Иван Петров, тел: +375171234567',
            nextContact: '08.06.2026',
            purpose: 'созвониться по КП',
            currentState: 'СЕКРЕТНЫЙ ТЕКСТ ТЕКУЩЕГО СОСТОЯНИЯ',
            coursesAttended: 'Летний курс',
            annualPlan: 'https://armis.by/',
        ));

        $rendered = $this->dump($org);
        self::assertStringNotContainsString('СЕКРЕТНЫЙ ТЕКСТ', $rendered);
        self::assertStringNotContainsString('ТЕКУЩЕГО СОСТОЯНИЯ', $rendered);
    }

    public function testCoursesAttendedIsStoredAsFreeText(): void
    {
        self::assertSame(
            'Курс по переговорам',
            $this->mapper->map($this->record('Нафтан', coursesAttended: 'Курс по переговорам'))->coursesAttended,
        );
    }

    public function testEmptyCoursesAttendedYieldsNull(): void
    {
        self::assertNull($this->mapper->map($this->record('Нафтан', coursesAttended: '   '))->coursesAttended);
    }

    public function testNameIsTruncatedTo255Characters(): void
    {
        $org = $this->mapper->map($this->record(str_repeat('я', 280)));

        self::assertSame(255, mb_strlen($org->name));
    }

    public function testCoursesAttendedIsTruncatedTo255Characters(): void
    {
        $org = $this->mapper->map($this->record('Нафтан', coursesAttended: str_repeat('К', 300)));

        self::assertSame(255, mb_strlen((string) $org->coursesAttended));
    }

    public function testMadeCallLandsAtNoonOfTheParsedDate(): void
    {
        $org = $this->mapper->map($this->record('Нафтан', interactions: '(25.08.2026) Пока потребности нет'));

        self::assertCount(1, $org->calls);
        self::assertSame('25.08.2026 12:00', $org->calls[0]->madeAt?->format('d.m.Y H:i'));
        self::assertNull($org->calls[0]->scheduledAt);
        self::assertSame('Пока потребности нет', $org->calls[0]->notes);
    }

    public function testSeveralDatedEntriesBecomeSeveralCalls(): void
    {
        $org = $this->mapper->map($this->record(
            'Нафтан',
            interactions: "(25.08.2026) Звонок 1\n(17.10.2025) Звонок 2",
        ));

        self::assertCount(2, $org->calls);
        self::assertSame('25.08.2026', $org->calls[0]->madeAt?->format('d.m.Y'));
        self::assertSame('Звонок 1', $org->calls[0]->notes);
        self::assertSame('17.10.2025', $org->calls[1]->madeAt?->format('d.m.Y'));
        self::assertSame('Звонок 2', $org->calls[1]->notes);
    }

    public function testNextContactAndPurposeBecomeOnePlannedCall(): void
    {
        $org = $this->mapper->map($this->record(
            'Нафтан',
            nextContact: '08.06.2026',
            purpose: 'созвониться по КП',
        ));

        self::assertCount(1, $org->calls);
        self::assertSame('08.06.2026 12:00', $org->calls[0]->scheduledAt?->format('d.m.Y H:i'));
        self::assertNull($org->calls[0]->madeAt);
        self::assertSame('созвониться по КП', $org->calls[0]->notes);
    }

    public function testPastNextContactDateIsStoredUnchanged(): void
    {
        $org = $this->mapper->map($this->record('Нафтан', nextContact: '08.06.2020', purpose: 'уточнить'));

        self::assertCount(1, $org->calls);
        self::assertSame('08.06.2020', $org->calls[0]->scheduledAt?->format('d.m.Y'));
    }

    #[DataProvider('providePlaceholderNextContactCreatesNoCallCases')]
    public function testPlaceholderNextContactCreatesNoCall(string $cell): void
    {
        $org = $this->mapper->map($this->record('Нафтан', nextContact: $cell, purpose: 'КП не нужен'));

        self::assertSame([], $org->calls, $cell);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function providePlaceholderNextContactCreatesNoCallCases(): iterable
    {
        yield 'пусто' => [''];
        yield 'дефис' => ['-'];
        yield 'подчёркивание' => ['_'];
        yield 'не актуально' => ['не актуально'];
        yield 'нет' => ['нет'];
    }

    public function testUnreadableNextContactCreatesNoCallAndIsNotSilentlyInvented(): void
    {
        $org = $this->mapper->map($this->record('Нафтан', nextContact: 'когда-нибудь', purpose: 'план'));

        self::assertSame([], $org->calls);
    }

    #[DataProvider('provideAnnualPlanColumnRoutesWebsiteOrIsDiscardedCases')]
    public function testAnnualPlanColumnRoutesWebsiteOrIsDiscarded(string $value, ?string $expected): void
    {
        $org = $this->mapper->map($this->record('Нафтан', annualPlan: $value));

        self::assertSame($expected, $org->website, $value);
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function provideAnnualPlanColumnRoutesWebsiteOrIsDiscardedCases(): iterable
    {
        yield 'URL со схемой' => ['https://armis.by/', 'https://armis.by/'];
        yield 'домен без схемы' => ['euroins.by', 'euroins.by'];
        yield 'URL с путём' => ['https://kpsr.by/kontakty/', 'https://kpsr.by/kontakty/'];
        yield 'утверждение о плане' => ['План составлен, договор подписан', null];
        yield 'пусто' => ['', null];
    }

    public function testAnnualPlanIsNeverPopulated(): void
    {
        // Объявленный формат источника годовой план не несёт, а URL из этой
        // колонки уходит в website. Проверяется значение, а не отсутствие
        // свойства: с приходом JSON-пути поле annualPlan в OrganizationData
        // появилось — его заполняет ответ, а не выгрузка (design D7).
        self::assertNull($this->mapper->map($this->record('Нафтан', annualPlan: 'https://armis.by/'))->annualPlan);
    }

    public function testTwoContactsInOneCellBecomeTwoContactDtos(): void
    {
        $org = $this->mapper->map($this->record(
            'Нафтан',
            contacts: 'Иван Петров, тел: +7-900-111-11-11, ivan@mail.ru; Мария Сидорова, тел: +7-900-222-22-22',
        ));

        self::assertCount(2, $org->contacts);
        self::assertSame('Иван Петров', $org->contacts[0]->name);
        self::assertSame('+7-900-111-11-11', $org->contacts[0]->phone);
        self::assertSame('ivan@mail.ru', $org->contacts[0]->email);
        self::assertSame('Мария Сидорова', $org->contacts[1]->name);
        self::assertSame('+7-900-222-22-22', $org->contacts[1]->phone);
    }

    public function testEmptyContactsColumnYieldsNoContacts(): void
    {
        self::assertSame([], $this->mapper->map($this->record('Нафтан', contacts: ''))->contacts);
    }

    public function testPositionIsSeparatedFromName(): void
    {
        $org = $this->mapper->map($this->record('Нафтан', contacts: 'Иван Петров, директор, тел: +375171234567'));

        self::assertSame('Иван Петров', $org->contacts[0]->name);
        self::assertSame('директор', $org->contacts[0]->position);
    }

    public function testPhoneOnlyFragmentIsImportedAsAnonymousContact(): void
    {
        $org = $this->mapper->map($this->record('Нафтан', contacts: '+7-900-111-11-11'));

        self::assertCount(1, $org->contacts);
        self::assertSame(ContactData::ANONYMOUS_NAME, $org->contacts[0]->name);
        self::assertSame('+7-900-111-11-11', $org->contacts[0]->phone);
    }

    /**
     * Телефон из ячейки «Контакты» приводится к каноническому виду: один и
     * тот же номер, записанный по-разному, обязан сохраниться одинаково.
     */
    public function testExtractedPhoneIsNormalisedToTheCanonicalForm(): void
    {
        $org = $this->mapper->map($this->record('Нафтан', contacts: 'Иван Петров, тел: 8017 212-34-56'));

        self::assertSame('+375 17 212-34-56', $org->contacts[0]->phone);
    }

    public function testPhoneWithoutTrunkPrefixIsNormalisedToo(): void
    {
        $org = $this->mapper->map($this->record('Нафтан', contacts: 'Мария Сидорова, тел: (29) 212-34-56'));

        self::assertSame('+375 29 212-34-56', $org->contacts[0]->phone);
    }

    public function testPhoneThatCannotBeNormalisedIsKeptAsIs(): void
    {
        $org = $this->mapper->map($this->record('Нафтан', contacts: 'Иван Петров, тел: 212-34-56'));

        self::assertSame('212-34-56', $org->contacts[0]->phone);
    }

    /**
     * Регрессия: «Иван Петров, тел: 8017 …» — это один контакт. Границы
     * контактов ищутся по маркеру «тел:», а не по виду имени, иначе собственная
     * фамилия принималась бы за начало следующего контакта.
     */
    public function testOwnSurnameIsNotMistakenForTheNextContact(): void
    {
        $org = $this->mapper->map($this->record('Нафтан', contacts: 'Иван Петров, тел: 8017 212-34-56'));

        self::assertCount(1, $org->contacts);
        self::assertSame('Иван Петров', $org->contacts[0]->name);
        self::assertSame('+375 17 212-34-56', $org->contacts[0]->phone);
    }

    public function testCommaSeparatedContactsAreSplitByThePhoneMarker(): void
    {
        $org = $this->mapper->map($this->record(
            'Нафтан',
            contacts: 'Иван Петров, тел: 8017 212-34-56, Мария Сидорова, тел: (29) 333-44-55',
        ));

        self::assertCount(2, $org->contacts);
        self::assertSame('Иван Петров', $org->contacts[0]->name);
        self::assertSame('+375 17 212-34-56', $org->contacts[0]->phone);
        self::assertSame('Мария Сидорова', $org->contacts[1]->name);
        self::assertSame('+375 29 333-44-55', $org->contacts[1]->phone);
    }

    public function testEmailNextToThePhoneStaysWithItsOwnContact(): void
    {
        $org = $this->mapper->map($this->record(
            'Нафтан',
            contacts: 'Иван Петров, тел: 8017 212-34-56, ivan@mail.ru',
        ));

        self::assertCount(1, $org->contacts);
        self::assertSame('Иван Петров', $org->contacts[0]->name);
        self::assertSame('+375 17 212-34-56', $org->contacts[0]->phone);
        self::assertSame('ivan@mail.ru', $org->contacts[0]->email);
    }

    public function testEmailOnlyFragmentIsImportedAsAnonymousContact(): void
    {
        $org = $this->mapper->map($this->record('Нафтан', contacts: 'info@example.by'));

        self::assertCount(1, $org->contacts);
        self::assertSame(ContactData::ANONYMOUS_NAME, $org->contacts[0]->name);
        self::assertSame('info@example.by', $org->contacts[0]->email);
    }

    public function testPhoneIsTruncatedTo32Characters(): void
    {
        $org = $this->mapper->map($this->record(
            'Нафтан',
            contacts: '+37529111223300000000000000000000000000000',
        ));

        self::assertCount(1, $org->contacts);
        self::assertSame(32, mb_strlen((string) $org->contacts[0]->phone));
    }

    public function testNameEmailAndPositionAreTruncatedTo255Characters(): void
    {
        $org = $this->mapper->map($this->record(
            'Нафтан',
            contacts: str_repeat('И', 300) . ', ' . str_repeat('Д', 300) . ', тел: +375171234567',
        ));

        self::assertSame(255, mb_strlen($org->contacts[0]->name));
        self::assertSame(255, mb_strlen((string) $org->contacts[0]->position));
    }

    public function testMultipleLinesInContactsColumnBecomeSeveralContacts(): void
    {
        $org = $this->mapper->map($this->record(
            'Нафтан',
            contacts: "Иван Петров, тел: +375171234567\nМария Сидорова, тел: +375291112233",
        ));

        self::assertCount(2, $org->contacts);
        self::assertSame('Иван Петров', $org->contacts[0]->name);
        self::assertSame('Мария Сидорова', $org->contacts[1]->name);
    }

    public function testUnrecognisedDateTokenStaysInNotesAndDatesNoCall(): void
    {
        $org = $this->mapper->map($this->record(
            'Нафтан',
            interactions: "(25.08.2026) Созвон\n(09.04.202) Созвон",
        ));

        self::assertCount(1, $org->calls);
        self::assertSame('25.08.2026', $org->calls[0]->madeAt?->format('d.m.Y'));
        self::assertStringContainsString('(09.04.202) Созвон', (string) $org->calls[0]->notes);
    }

    public function testInteractionTextWithoutAnyDateBecomesACallWithoutDate(): void
    {
        $org = $this->mapper->map($this->record('Нафтан', interactions: 'Ни одной даты, только текст'));

        self::assertCount(1, $org->calls);
        self::assertNull($org->calls[0]->madeAt);
        self::assertNull($org->calls[0]->scheduledAt);
        self::assertSame('Ни одной даты, только текст', $org->calls[0]->notes);
    }

    private function record(
        string $company,
        string $course = '',
        string $interactions = '',
        string $contacts = '',
        string $nextContact = '',
        string $purpose = '',
        string $currentState = '',
        string $coursesAttended = '',
        string $annualPlan = '',
    ): array {
        $fixture = (new ImportCsvFixture())
            ->row($company, $course, $interactions, $contacts, $nextContact, $purpose, $currentState, $coursesAttended, $annualPlan);
        $parser = new CsvParser();

        return iterator_to_array($parser->records($this->tempFile($fixture->content())))[0];
    }

    private function dump(object $dto): string
    {
        return var_export($dto, true);
    }

    private function tempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'csv-');
        self::assertIsString($path);
        file_put_contents($path, $contents);

        return $path;
    }
}
