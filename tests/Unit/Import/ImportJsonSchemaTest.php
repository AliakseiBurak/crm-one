<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import;

use App\Service\Import\Exception\JsonPayloadException;
use App\Service\Import\ImportJsonSchema;
use App\Service\Import\InteractionDateParser;
use App\Service\Import\JsonImportParser;
use App\Service\Import\JsonSchemaViolation;
use App\Service\Import\JsonValidationResult;
use JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Опубликованный контракт ответа и разбор ответа в DTO (change
 * add-organizations-json-import, design D2).
 */
final class ImportJsonSchemaTest extends TestCase
{
    private ImportJsonSchema $schema;

    private JsonImportParser $parser;

    protected function setUp(): void
    {
        $this->schema = new ImportJsonSchema(
            \dirname(__DIR__, 3),
            new Validator(),
        );
        $this->parser = new JsonImportParser(new InteractionDateParser());
    }

    #[Test]
    public function theDocumentIsPublishedAsDownloadableJson(): void
    {
        self::assertFileExists($this->schema->path());
        // Документ читается без потерь и используется валидатором как объект:
        // сравнение идёт по разобранному дереву, а не по записи.
        self::assertSame(
            $this->schema->raw(),
            json_decode((string) file_get_contents($this->schema->path()), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function theContractCarriesTheColumnsItCanStore(): void
    {
        $schema = $this->schema->raw();

        // Формат не запрещает поля, а объявляет те, что у него есть куда положить.
        // Раньше `unp`, `is_main` и `annualPlan` считались недопустимыми, потому что
        // модель их выдумывает, — но файл из другого источника приносит их
        // настоящими, и отвергать такой ответ целиком было неверно.
        self::assertSame(32, $schema['definitions']['organization']['properties']['unp']['maxLength']);
        self::assertSame(
            255,
            $schema['definitions']['organization']['properties']['annualPlan']['maxLength'],
        );
        self::assertSame(
            'boolean',
            $schema['definitions']['contact']['properties']['isMain']['type'],
        );

        // Поля, которых в формате нет, по-прежнему не выдумываются: промпт прямо
        // говорит модели не их заполнять, а пустое поле — это не то же самое, что
        // выдуманное значение.
        self::assertStringContainsString('не выдумывай', $this->schema->prompt());

        // `format` этой библиотекой не проверяется: непроверяемое ключевое слово
        // в опубликованном контракте хуже его отсутствия.
        self::assertArrayNotHasKey('format', $schema['properties']['organizations']);
    }

    #[Test]
    public function anAnswerWithOnlyNamesIsAccepted(): void
    {
        // Формат общий, а не только для выгрузки CRM: список из одних названий —
        // полноценный ответ, а не «почти пустой».
        $result = $this->schema->validateText('{"organizations":[{"name":"АбесТрейд"},{"name":"Нафтан"}]}');

        self::assertTrue($result->isValid(), $result->report());
        self::assertSame(
            ['АбесТрейд', 'Нафтан'],
            $this->parser->organizationNames($this->schema->decode('{"organizations":[{"name":"АбесТрейд"},{"name":"Нафтан"}]}')),
        );
    }

    #[Test]
    public function aTooLongUnpIsRejected(): void
    {
        $result = $this->schema->validateText(json_encode([
            'organizations' => [['name' => 'АбесТрейд', 'unp' => str_repeat('1', 33)]],
        ], JSON_THROW_ON_ERROR));

        self::assertFalse($result->isValid());
        self::assertContains('unp', $this->fieldsOf($result, 0));
    }

    #[Test]
    public function isMainMustBeABoolean(): void
    {
        $result = $this->schema->validateText(
            '{"organizations":[{"name":"АбесТрейд","contacts":[{"name":"Иван","isMain":"да"}]}]}',
        );

        self::assertFalse($result->isValid());
        self::assertContains('contacts[0].isMain', $this->fieldsOf($result, 0));
    }

    #[Test]
    public function everyOrganizationPropertyIsDescribedWithItsOwnConstraint(): void
    {
        $schema = $this->schema->raw();
        $properties = $schema['definitions']['organization']['properties'];

        foreach ($properties as $name => $definition) {
            // Описание может лежать в самом свойстве или в Definition, на которую
            // оно ссылается: не важно где, важно что оно есть — иначе поле
            // выпадает из промпта.
            if (isset($definition['$ref'])) {
                $definition = $schema['definitions'][substr($definition['$ref'], \strlen('#/definitions/'))] ?? [];
            }

            self::assertArrayHasKey('description', $definition, \sprintf('Поле %s без описания.', $name));
            self::assertNotSame('', trim((string) $definition['description']));
        }

        // Поле, которого нет в формате, нет и в словаре промпта: иначе модель
        // пришлось бы присылать то, что проверка затем отвергнет.
        self::assertSame(
            [
                'name', 'industry', 'city', 'website', 'description', 'coursesAttended',
                'contacts', 'calls', 'nextCall', 'unp', 'annualPlan',
            ],
            array_keys($this->schema->fieldDictionary()),
        );

        // Ограничения, о которых модель не знает заранее, невыполнимы: описание
        // обязано называть длину, а обязательность — быть в `required`.
        self::assertStringContainsString('255', $properties['name']['description']);
        self::assertSame(['name'], $schema['definitions']['organization']['required']);
        self::assertSame(32, $schema['definitions']['contact']['properties']['phone']['maxLength']);

        // `nextCall` описан в своей Definition, а не у ссылки на неё, — и
        // промпт должен получать именно это описание.
        self::assertSame(
            $schema['definitions']['nextCall']['description'],
            $this->schema->fieldDictionary()['nextCall'],
        );
    }

    #[Test]
    public function thePromptCarriesEverySchemaDescription(): void
    {
        $prompt = $this->schema->prompt();

        foreach ($this->schema->fieldDictionary() as $description) {
            self::assertStringContainsString($description, $prompt);
        }

        // Форматы даты живут в описаниях звонка и следующего контакта: без них в
        // промпте модель узнала бы о грамматике только после отказа проверки.
        foreach ($this->schema->nestedFieldDictionary() as $fields) {
            foreach ($fields as $description) {
                self::assertStringContainsString($description, $prompt);
            }
        }

        // Грамматика даты у звонка и следующего контакта — одна и та же, иначе
        // плановая дата принималась бы там, где совершённая отвергается.
        self::assertSame(
            $this->schema->raw()['definitions']['call']['properties']['date']['pattern'],
            $this->schema->raw()['definitions']['nextCall']['properties']['date']['pattern'],
        );

        // Промпт учит формату до того, как модель пишет: правила даты и
        // максимальные длины названы прямо в тексте.
        self::assertStringContainsString('21.10.25', $prompt);
        self::assertStringContainsString('до 32 символов', $prompt);
        self::assertStringContainsString('не возвращай это поле', $prompt);
        self::assertStringContainsString('обязательное', mb_strtolower($prompt));
    }

    #[Test]
    public function thePromptCarriesNoWorkedExample(): void
    {
        $prompt = $this->schema->prompt();

        // Примера в промпте нет: модель возвращала его организацию с контактом и
        // телефоном как данные. Формат объясняют слова и словарь полей.
        self::assertStringNotContainsString('Организация-пример', $prompt);
        self::assertStringNotContainsString('Имя-контакта', $prompt);
        self::assertStringNotContainsString('example.com', $prompt);
        self::assertStringNotContainsString('000000000000', $prompt);

        // Вместо примера — правило, как пишется отсутствие данных: поле без
        // данных не возвращается, заглушки запрещены. Иначе модель заполняет
        // неизвестное «примером» и повторяет один контакт на всех организации.
        self::assertStringContainsString('заглушек не бывает', $prompt);
        self::assertStringContainsString('не возвращаются вовсе', $prompt);
        self::assertStringContainsString('ключ organizations', $prompt);

        // УНП объяснён в обе стороны: выдумывать нельзя, найденное переносится.
        self::assertStringContainsString('не выдумывай', $prompt);
        self::assertStringContainsString('переноси из источника как есть', $prompt);
    }

    #[Test]
    public function theProviderGetsTheRootOfTheContractAsItsResponseFormat(): void
    {
        $format = $this->schema->providerSchema();

        // Проекция свойства `organizations` — это схема массива, и провайдер по
        // ней отвечает голым массивом `[ {...} ]`, который импорт не принимает.
        self::assertSame('object', $format['type']);
        self::assertSame(['organizations'], $format['required']);
        self::assertSame(['organizations'], array_keys($format['properties']));
        self::assertSame('array', $format['properties']['organizations']['type']);
        self::assertSame(1, $format['properties']['organizations']['minItems']);

        // Ключевые слова, которые движок структурированного вывода не понимает,
        // убраны: `$ref` в схеме обрывает грамматику Ollama, `pattern` и
        // `maxLength` — не поддерживаются вовсе. Проверку длины и дат сервер всё
        // равно делает у себя по полному документу.
        $encoded = json_encode($format, JSON_THROW_ON_ERROR);
        foreach (['$ref', '$id', '$schema', 'pattern', 'maxLength', 'additionalProperties'] as $keyword) {
            self::assertStringNotContainsString('"' . $keyword . '"', $encoded);
        }

        // Имена полей и обязательность в проекции остаются: иначе провайдер не
        // держит форму ответа.
        $organization = $format['properties']['organizations']['items'];
        self::assertSame(['name'], $organization['required']);
        self::assertArrayHasKey('nextCall', $organization['properties']);
        self::assertArrayHasKey('contacts', $organization['properties']);
    }

    #[Test]
    public function aGoodAnswerHasNoViolations(): void
    {
        $result = $this->schema->validateText($this->payload());

        self::assertTrue($result->isValid(), $result->report());
        self::assertSame([], $result->violations);
    }

    #[Test]
    public function everyViolationIsReportedWithItsOrganizationAndField(): void
    {
        $payload = json_encode(['organizations' => [
            ['name' => 'АбесТрейд', 'contacts' => [['name' => 'Вячеслав', 'phone' => str_repeat('9', 40)]]],
            ['name' => 'Второе'],
            ['name' => ''],
            ['name' => 'Четвёртое'],
            ['name' => 'Пятое'],
            ['name' => 'Шестое', 'contacts' => [['name' => 'Кто-то', 'phone' => str_repeat('8', 40)]]],
        ]], JSON_THROW_ON_ERROR);

        $result = $this->schema->validateText($payload);

        self::assertFalse($result->isValid());

        // Пустое имя у третьей и длинный телефон у первой и шестой: номер в
        // отчёте — это позиция в массиве, а не индекс с нуля.
        self::assertContains('name', $this->fieldsOf($result, 2));

        // Поле называется путём внутри организации, а не только своим именем:
        // `contacts[0].phone` отличает телефон первого контакта от третьего.
        self::assertContains('contacts[0].phone', $this->fieldsOf($result, 0));
        self::assertContains('contacts[0].phone', $this->fieldsOf($result, 5));

        foreach ($result->violations as $violation) {
            self::assertNotSame('', $violation->message);
        }

        // Отчёт администратору называет организацию по номеру и поле.
        self::assertStringContainsString('№3', $result->report());
        self::assertStringContainsString('«name»', $result->report());
        self::assertStringContainsString('name', $result->report());
    }

    #[Test]
    public function aMisspelledKeyIsRejectedRatherThanIgnored(): void
    {
        // Опечатку пропускать нельзя: поле молча потерялось бы, и администратор
        // увидел бы организацию без данных, которых он ждал. Именно поэтому поля
        // формата объявлены явно, а неизвестное имя отвергается.
        $result = $this->schema->validateText('{"organizations":[{"nmae":"Нафтан"}]}');

        self::assertFalse($result->isValid());
        self::assertStringContainsString('nmae', $result->report());
    }

    #[Test]
    public function anEmptyAnswerIsRejected(): void
    {
        $result = $this->schema->validateText('{"organizations":[]}');

        self::assertFalse($result->isValid());
        self::assertStringContainsString('organizations', $result->report());
    }

    #[Test]
    public function aMissingOrganizationsKeyIsRejected(): void
    {
        $result = $this->schema->validateText('{"companies":[]}');

        self::assertFalse($result->isValid());
    }

    #[Test]
    public function malformedJsonIsRefusedBeforeAnyValidation(): void
    {
        $this->expectException(JsonPayloadException::class);

        $this->schema->validateText('{не json');
    }

    #[Test]
    public function theOrganizationCountIsWhatTotalRowsCounts(): void
    {
        $payload = $this->schema->decode($this->payload());

        self::assertSame(1, $this->schema->organizationCount($payload));
        self::assertSame(0, $this->schema->organizationCount(null));
    }

    #[Test]
    #[DataProvider('provideADateTheSchemaAcceptsIsOneTheDateParserReadsCases')]
    public function aDateTheSchemaAcceptsIsOneTheDateParserReads(string $date): void
    {
        $result = $this->schema->validateText(json_encode([
            'organizations' => [['name' => 'АбесТрейд', 'calls' => [['date' => $date]]]],
        ], JSON_THROW_ON_ERROR));

        self::assertTrue($result->isValid(), \sprintf('Дата %s отвергнута схемой.', $date));

        // Схема и разбор даты — две записи одной грамматики; если они разойдутся,
        // год окажется «валидным», но нечитаемым.
        self::assertNotNull(
            (new InteractionDateParser())->parseDate($date),
            \sprintf('Схема принимает дату %s, а разбор её не читает.', $date),
        );
    }

    #[Test]
    #[DataProvider('provideADateWithoutAFullUnambiguousYearIsRejectedAndNeverRepairedCases')]
    public function aDateWithoutAFullUnambiguousYearIsRejectedAndNeverRepaired(string $date): void
    {
        // Год не выводится из соседней записи и не достраивается: ответ с такой
        // датой отклоняется целиком.
        $result = $this->schema->validateText(json_encode([
            'organizations' => [['name' => 'АбесТрейд', 'calls' => [['date' => $date]]]],
        ], JSON_THROW_ON_ERROR));

        self::assertFalse($result->isValid(), \sprintf('Дата %s прошла проверку.', $date));
        self::assertNull((new InteractionDateParser())->parseDate($date));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideADateTheSchemaAcceptsIsOneTheDateParserReadsCases(): iterable
    {
        yield 'точка, однозначные' => ['8.04.2025'];
        yield 'точка, двузначные' => ['29.05.2026'];
        yield 'двузначный год' => ['21.10.25'];
        yield 'косая черта' => ['17/09/2025'];
        yield 'запятая' => ['08,07.2025'];
        yield 'хвостовое подчёркивание' => ['05.11.2025_'];
        yield 'хвостовая точка' => ['05.11.2025.'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideADateWithoutAFullUnambiguousYearIsRejectedAndNeverRepairedCases(): iterable
    {
        yield 'без года' => ['31.08'];
        yield 'трёхзначный год' => ['09.04.202'];
        yield 'пятизначный год' => ['09.04.20255'];
        yield 'в скобках, как в CSV' => ['(8.04.2025)'];
        yield 'с текстом вокруг' => ['8.04.2025 Созвон'];
        yield 'ISO 8601' => ['2025-04-08'];
    }

    #[Test]
    public function theParserMapsAValidAnswerToTheSameDtosAsTheCsvPath(): void
    {
        $organizations = $this->parser->organizations($this->schema->decode($this->payload()));
        $organization = $organizations[0];

        self::assertSame('АбесТрейд', $organization->name);
        self::assertSame('ИТ-дистрибьютор', $organization->industry);
        self::assertSame('Минск', $organization->city);
        self::assertSame('https://abeslab.by', $organization->website);
        self::assertSame('Сертифицированный дистрибьютор ПО.', $organization->description);
        self::assertSame('191000000', $organization->unp);
        self::assertCount(1, $organization->contacts);
        self::assertSame('Вячеслав', $organization->contacts[0]->name);
        self::assertSame('+375339027636', $organization->contacts[0]->phone);
    }

    #[Test]
    public function anAnswerCarryingUnpAndAnnualPlanIsMapped(): void
    {
        $payload = '{"organizations":[{"name":"Нафтан","unp":"191000000",'
            . '"annualPlan":"подписать договор в декабре"}]}';

        $organization = $this->parser->organizations($this->schema->decode($payload))[0];

        // Принесённые файлом значения сохраняются: файл из другого источника —
        // настоящий источник, а не выдумка модели.
        self::assertSame('191000000', $organization->unp);
        self::assertSame('подписать договор в декабре', $organization->annualPlan);
    }

    #[Test]
    public function theMainMarkFromTheAnswerReachesTheContact(): void
    {
        $payload = '{"organizations":[{"name":"АбесТрейд","contacts":['
            . '{"name":"Вячеслав","isMain":true},{"name":"Мария"}]}]}';

        $contacts = $this->parser->organizations($this->schema->decode($payload))[0]->contacts;

        self::assertTrue($contacts[0]->isMain);
        self::assertFalse($contacts[1]->isMain);
    }

    #[Test]
    public function theRowSignatureNoticesAChangedUnp(): void
    {
        $first = $this->schema->decode('{"organizations":[{"name":"Нафтан","unp":"191000000"}]}');
        $second = $this->schema->decode('{"organizations":[{"name":"Нафтан","unp":"191111111"}]}');

        self::assertNotSame(
            $this->parser->rowSignature($first->organizations[0]),
            $this->parser->rowSignature($second->organizations[0]),
        );
    }

    #[Test]
    public function aMadeCallKeepsItsDateAndAPlannedCallIsScheduled(): void
    {
        $calls = $this->parser->organizations($this->schema->decode($this->payload()))[0]->calls;

        // Дата читается той же грамматикой, что и на вкладке CSV, и хранится в
        // полдень своей даты.
        self::assertSame('2026-05-29 12:00', $calls[0]->madeAt?->format('Y-m-d H:i'));
        self::assertSame('Направила КП по всем лагерям', $calls[0]->notes);

        // `nextCall` — плановый звонок: плановая дата есть, дата звонка нет.
        self::assertSame('2026-06-08 12:00', $calls[1]->scheduledAt?->format('Y-m-d H:i'));
        self::assertNull($calls[1]->madeAt);
        self::assertSame('созвониться по КП', $calls[1]->notes);
    }

    #[Test]
    public function aTwoDigitYearIsReadAsTwenty(): void
    {
        $payload = '{"organizations":[{"name":"АбесТрейд","calls":[{"date":"21.10.25"}]}]}';

        $call = $this->parser->organizations($this->schema->decode($payload))[0]->calls[0];

        self::assertSame('2025-10-21 12:00', $call->madeAt?->format('Y-m-d H:i'));
    }

    #[Test]
    public function anAbsentOptionalFieldIsNullAndNeverAnEmptyString(): void
    {
        $payload = '{"organizations":[{"name":"АбесТрейд"}]}';

        $organization = $this->parser->organizations($this->schema->decode($payload))[0];

        self::assertNull($organization->industry);
        self::assertNull($organization->city);
        self::assertNull($organization->website);
        self::assertSame([], $organization->contacts);
        self::assertSame([], $organization->calls);
    }

    #[Test]
    public function emptyStringValuesReadAsAbsent(): void
    {
        // Пустая строка в ответе — это «не знаю», а не «знаю, что пусто»: в базе
        // она иначе осталась бы неотличимой от отсутствия поля.
        $payload = '{"organizations":[{"name":"АбесТрейд","industry":"","city":"   "}]}';

        $organization = $this->parser->organizations($this->schema->decode($payload))[0];

        self::assertNull($organization->industry);
        self::assertNull($organization->city);
    }

    #[Test]
    public function aContactWithOnlyAPhoneIsCreatedUnderTheAnonymousName(): void
    {
        $payload = '{"organizations":[{"name":"АбесТрейд","contacts":[{"name":"","phone":"+375171234567"}]}]}';

        $contact = $this->parser->organizations($this->schema->decode($payload))[0]->contacts[0];

        self::assertSame('Без имени', $contact->name);
        self::assertSame('+375171234567', $contact->phone);
    }

    #[Test]
    public function theRowSignatureIgnoresKeyOrderAndPayloadFormatting(): void
    {
        $first = $this->schema->decode(
            '{"organizations":[{"name":"АбесТрейд","city":"Минск","calls":[{"date":"29.05.2026","notes":"КП"}]}]}',
        );
        $second = $this->schema->decode(
            '{ "organizations" : [ { "calls" : [ { "notes" : "КП" , "date" : "29.05.2026" } ] ,'
            . ' "city" : "Минск" , "name" : "АбесТрейд" } ] }',
        );

        self::assertSame(
            $this->parser->rowSignature($first->organizations[0]),
            $this->parser->rowSignature($second->organizations[0]),
        );
    }

    #[Test]
    public function theRowSignatureNoticesAChangedField(): void
    {
        $first = $this->schema->decode('{"organizations":[{"name":"АбесТрейд","city":"Минск"}]}');
        $second = $this->schema->decode('{"organizations":[{"name":"АбесТрейд","city":"Брест"}]}');

        self::assertNotSame(
            $this->parser->rowSignature($first->organizations[0]),
            $this->parser->rowSignature($second->organizations[0]),
        );
    }

    #[Test]
    public function aResultWithoutViolationsIsValid(): void
    {
        $result = new JsonValidationResult();

        self::assertTrue($result->isValid());
        self::assertSame('', $result->report());
        self::assertSame([], $result->forOrganization(0));
    }

    #[Test]
    public function aViolationWithoutAnOrganizationIsNamedAsTheWholeAnswer(): void
    {
        $result = new JsonValidationResult([
            new JsonSchemaViolation(ImportJsonSchema::NO_ORGANIZATION, 'ответ', 'ожидается объект'),
        ]);

        self::assertStringContainsString('ответ', $result->report());
    }

    private function payload(): string
    {
        return json_encode(['organizations' => [[
            'name' => 'АбесТрейд',
            'industry' => 'ИТ-дистрибьютор',
            'city' => 'Минск',
            'website' => 'https://abeslab.by',
            'unp' => '191000000',
            'description' => 'Сертифицированный дистрибьютор ПО.',
            'contacts' => [[
                'name' => 'Вячеслав',
                'position' => 'начальник отдела обучения',
                'phone' => '+375339027636',
                'email' => 'V.Zakrevsky@naftan.by',
            ]],
            'calls' => [['date' => '29.05.2026', 'notes' => 'Направила КП по всем лагерям']],
            'nextCall' => ['date' => '08.06.2026', 'purpose' => 'созвониться по КП'],
        ]]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return string[]
     */
    private function fieldsOf(JsonValidationResult $result, int $index): array
    {
        return array_map(
            static fn(JsonSchemaViolation $violation): string => $violation->field,
            $result->forOrganization($index),
        );
    }
}
