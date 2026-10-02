<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Service\Import\Exception\JsonPayloadException;
use JsonSchema\Validator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Опубликованный контракт ответа языковой модели (design D2).
 *
 * Один документ — `resources/import/organization-import.schema.json` — питает
 * три потребителя: словарь полей промпта, скачиваемый файл и проверку ответа.
 * Поэтому промпт и валидатор разойтись не могут в принципе, а не «маловероятно»:
 * модель получает ровно те ограничения, которые затем применяются к её ответу.
 *
 * Ответ декодируется в объекты (`json_decode` без `assoc`), а не в массивы:
 * валидатор различает JSON-объект и массив, и PHP-массив для `type: object` —
 * это «найден массив, требовался объект» на каждой организации.
 */
final class ImportJsonSchema
{
    /**
     * Индекс организации, когда нарушение относится к ответу целиком, а не к
     * одной организации: отсутствует ключ `organizations`, пустой массив,
     * неожиданное поле верхнего уровня.
     */
    public const int NO_ORGANIZATION = -1;

    /**
     * @var array<string, mixed>|null
     */
    private ?array $schema = null;

    /**
     * @var \stdClass|null
     */
    private ?\stdClass $schemaObject = null;

    public function __construct(
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
        private readonly Validator $validator,
    ) {}

    /**
     * Путь к опубликованному документу — тот же файл отдаётся на скачивание.
     */
    public function path(): string
    {
        return $this->projectDir . \DIRECTORY_SEPARATOR . 'resources/import/organization-import.schema.json';
    }

    /**
     * Документ как массив — для чтения описаний полей.
     *
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->schema ??= $this->decodeSchema($this->contents());
    }

    /**
     * Словарь полей для промпта: имя свойства → его описание.
     *
     * Описание берётся из самой схемы, поэтому промпт наследует и правила дат,
     * и максимальные длины, и обязательность: ограничение, о котором модель не
     * знает заранее, невыполнимо по построению.
     *
     * @return array<string, string>
     */
    public function fieldDictionary(): array
    {
        $schema = $this->raw();
        $organizations = $schema['definitions']['organization']['properties'] ?? [];

        $dictionary = [];
        foreach ($organizations as $property => $definition) {
            // Поле, объявленное через `$ref`, описывается в своей Definition: иначе
            // `nextCall` остался бы в промпте безо всякого объяснения.
            if (\is_array($definition) && isset($definition['$ref'])) {
                $definition = $schema['definitions'][substr($definition['$ref'], \strlen('#/definitions/'))] ?? [];
            }

            if (\is_array($definition) && isset($definition['description'])) {
                $dictionary[$property] = (string) $definition['description'];
            }
        }

        return $dictionary;
    }

    /**
     * Словарь полей вложенной части ответа: `contact`, `call`, `nextCall`.
     *
     * Читается из тех же `definitions`, что и организация, поэтому ограничения
     * вложенных полей — те же самые, что проверяет валидатор. Форматы даты живут
     * именно здесь, и без этого словаря модель узнала бы о них только постфактум.
     *
     * @return array<string, array<string, string>> имя секции => (поле => описание)
     */
    public function nestedFieldDictionary(): array
    {
        $schema = $this->raw();

        $sections = [];
        foreach (['contact', 'call', 'nextCall'] as $section) {
            $properties = $schema['definitions'][$section]['properties'] ?? [];

            $fields = [];
            foreach ($properties as $property => $definition) {
                if (\is_array($definition) && isset($definition['description'])) {
                    $fields[$property] = (string) $definition['description'];
                }
            }

            $sections[$section] = $fields;
        }

        return $sections;
    }

    /**
     * Проекция контракта для внешнего провайдера.
     *
     * Провайдеру нельзя отдать документ целиком: Ollama превращает схему в
     * грамматику для генерации и падает на `$ref`, `$id`, `$schema` и прочих
     * ключах, которых в его генераторе нет, — «Failed to initialize samplers:
     * failed to parse grammar». Здесь остаётся только костяк формы: типы,
     * свойства, обязательность, элементы массивов и `minItems`.
     *
     * Отброшенные ограничения при этом не теряются: длины, форматы дат и
     * дополнительные свойства проверяет сервер, на который ответ попадает
     * неизбежно. Провайдеру нужно одно — чтобы ответ был разбираемым объектом
     * нужной формы, а не верность каждого символа.
     *
     * Проекция выводится из того же документа, поэтому разойтись с проверкой
     * не может: тест сверяет, что в проекции нет запрещённых ключей, а ответ,
     * проходящий проекцию, проходит и полный контракт.
     *
     * @return array<string, mixed>
     */
    public function providerSchema(): array
    {
        // Проецируется корень документа, а не его свойство `organizations`.
        // Проекция свойства — это схема массива, и Ollama по ней честно отдаёт
        // `[ {...} ]`: ответ без объекта-обёртки, который сервер не примет.
        // Формат ответа — корень, всё остальное следует из него.
        return $this->skeleton($this->raw());
    }

    /**
     * Костяк подсхемы: `$ref` разворачивается, остаются только ключи, которые
     * генератор грамматики понимает.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private function skeleton(array $schema): array
    {
        $schema = $this->resolveRef($schema);

        $skeleton = [];
        foreach (['type', 'required', 'minItems', 'enum'] as $keyword) {
            if (isset($schema[$keyword])) {
                $skeleton[$keyword] = $schema[$keyword];
            }
        }

        if (isset($schema['properties']) && \is_array($schema['properties'])) {
            $skeleton['properties'] = array_map(
                fn($property): array => \is_array($property) ? $this->skeleton($property) : [],
                $schema['properties'],
            );
        }

        if (isset($schema['items']) && \is_array($schema['items'])) {
            $skeleton['items'] = $this->skeleton($schema['items']);
        }

        return $skeleton;
    }

    /**
     * Ссылка `#/definitions/<name>` заменяется самой Definition.
     *
     * Разворачивание, а не отбрасывание: иначе `items` организации превратился бы
     * в пустой объект, и провайдер сгенерировал бы пустой массив вместо списка
     * организаций.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private function resolveRef(array $schema): array
    {
        $ref = $schema['$ref'] ?? null;
        if (!\is_string($ref) || !str_starts_with($ref, '#/definitions/')) {
            return $schema;
        }

        $name = substr($ref, \strlen('#/definitions/'));
        $definitions = $this->raw()['definitions'] ?? [];

        return \is_array($definitions[$name] ?? null) ? $definitions[$name] : [];
    }

    /**
     * Готовый к копированию промпт: задача, формат ответа и словарь полей.
     *
     * Собирается из схемы, а не пишется руками, поэтому поле нельзя убрать из
     * промпта, не убрав его из проверки, и наоборот.
     */
    public function prompt(): string
    {
        $lines = [
            'Нужно разобрать список организаций и вернуть его в виде JSON по схеме ниже.',
            '',
            'Источник может быть любым: выгрузка CRM, таблица, письмо, переписка. Каждая',
            'организация описывается один раз, а её поля разнесены по своим именам, а не',
            'собраны в одной строке.',
            '',
            'Правила, которые делают ответ пригодным для импорта:',
            '- ответ — объект с обязательным массивом organizations, в котором не меньше одной',
            '  организации. Ни массивом, ни объектом без organizations ответ не будет:',
            '  импорту нужен ключ organizations;',
            '- у организации обязательно только название. Остальные поля не возвращаются вовсе,',
            '  если данных нет, — так и делай;',
            '- отсутствующие данные просто не возвращаются: пустая строка и отсутствие поля —',
            '  разные вещи;',
            '- заглушек не бывает: вместо данных не пиши «пример», «тест», «неизвестно», «-» и',
            '  тому подобное. Один и тот же контакт, телефон или адрес у нескольких',
            '  организаций — тоже выдумка: у каждой организации своё, и то, что есть в',
            '  источнике;',
            '- ничего не выдумывай и не достраивай: дата без дня, месяца или года',
            '  недопустима, а год никогда не восстанавливается из соседней записи;',
            '- поле unp переноси из источника как есть, когда оно там есть, и не возвращай,',
            '  когда его нет: УНП выдумывать нельзя, а найденное в источнике — можно. То же',
            '  с annualPlan;',
            '- пустые массивы contacts и calls означают «контактов нет» и «звонков не было»,',
            '  а не «данные забыли».',
            '',
            'Поля организации:',
        ];

        foreach ($this->fieldDictionary() as $property => $description) {
            $lines[] = \sprintf('- %s — %s', $property, $description);
        }

        $lines[] = '';
        foreach ([
            'contact' => 'Поля контакта:',
            'call' => 'Поля звонка:',
            'nextCall' => 'Поля следующего звонка:',
        ] as $section => $heading) {
            $lines[] = $heading;
            foreach ($this->nestedFieldDictionary()[$section] as $property => $description) {
                $lines[] = \sprintf('- %s — %s', $property, $description);
            }
            $lines[] = '';
        }

        // Примера в промпте нет: модель возвращала его содержимое как данные —
        // организация «из примера» с чужим контактом и телефоном. Пример из
        // заведомо выдуманных значений учил ровно тому же, а убрать его было
        // дешевле, чем объяснять модели, что показанное не является данными.
        return implode("\n", $lines);
    }

    /**
     * Разбор ответа: `stdClass`-дерево, с которым работает и валидатор, и
     * разбор в DTO.
     *
     * @throws JsonPayloadException текст не является корректным JSON
     */
    public function decode(string $text): mixed
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($text, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new JsonPayloadException(\sprintf('Ответ не является корректным JSON: %s', $e->getMessage()));
        }

        return $decoded;
    }

    /**
     * Проверка уже разобранного ответа по опубликованной схеме.
     *
     * Нарушения возвращаются все, а не первое: администратор правит ответ, и
     * найти одну ошибку в сотне организаций дороже, чем прочитать весь список.
     */
    public function validate(mixed $payload): JsonValidationResult
    {
        if (!\is_object($payload)) {
            return new JsonValidationResult([
                new JsonSchemaViolation(
                    self::NO_ORGANIZATION,
                    'ответ',
                    'ожидается объект JSON с полем organizations',
                ),
            ]);
        }

        $this->validator->validate($payload, $this->schemaObject());

        $violations = [];
        foreach ($this->validator->getErrors() as $error) {
            [$index, $field] = $this->locate($error['property'] ?? '');
            $violations[] = new JsonSchemaViolation($index, $field, $this->explain($error));
        }

        return new JsonValidationResult($violations);
    }

    /**
     * Разбор и проверка одной строкой — так путь «отправил ответ» идёт на
     * сервере.
     */
    public function validateText(string $text): JsonValidationResult
    {
        return $this->validate($this->decode($text));
    }

    /**
     * Количество организаций в разобранном ответе.
     */
    public function organizationCount(mixed $payload): int
    {
        if (!\is_object($payload) || !isset($payload->organizations) || !\is_array($payload->organizations)) {
            return 0;
        }

        return \count($payload->organizations);
    }

    /**
     * Путь в нарушении → (индекс организации, название поля).
     *
     * Валидатор отдаёт путь вида `organizations[3].contacts[0].phone`. Всё, что
     * после `organizations[N]`, — это путь внутри организации и показывается
     * как есть: `contacts[0].phone` называет поле точнее, чем `phone`.
     *
     * @return array{int, string}
     */
    private function locate(string $property): array
    {
        if (!str_starts_with($property, 'organizations')) {
            return [self::NO_ORGANIZATION, '' === $property ? 'ответ' : $property];
        }

        if (1 !== preg_match('/^organizations\[(\d+)\]?(.*)$/', $property, $m)) {
            return [self::NO_ORGANIZATION, 'organizations'];
        }

        $index = (int) $m[1];
        $rest = $m[2];

        return [$index, '' === $rest ? 'организация' : ltrim($rest, '.')];
    }

    /**
     * @param array<string, mixed> $error
     */
    private function explain(array $error): string
    {
        /** @var array{name: string, params: array<string, mixed>} $constraint */
        $constraint = $error['constraint'] ?? ['name' => '', 'params' => []];
        $params = $constraint['params'];

        return match ($constraint['name']) {
            'required' => \sprintf('обязательное поле «%s» отсутствует', (string) $error['property']),
            'minLength' => 1 === (int) ($params['minLength'] ?? 1)
                ? 'значение не может быть пустым'
                : \sprintf('строка короче %d символов', (int) $params['minLength']),
            'maxLength' => \sprintf(
                'строка длиннее %d символов',
                (int) ($params['maxLength'] ?? 0),
            ),
            'pattern' => 'не соответствует объявленному формату',
            'minItems' => \sprintf(
                'в массиве меньше %d элементов',
                (int) ($params['minItems'] ?? 0),
            ),
            'additionalProp' => \sprintf(
                'поля «%s» нет в формате ответа',
                (string) ($params['property'] ?? ''),
            ),
            'type' => \sprintf(
                'ожидается %s, а получено %s',
                $this->typeName((string) ($params['expected'] ?? '')),
                $this->typeName((string) ($params['found'] ?? '')),
            ),
            default => (string) ($error['message'] ?? 'значение не соответствует формату'),
        };
    }

    private function typeName(string $type): string
    {
        return match ($type) {
            'object' => 'объект',
            'array' => 'массив',
            'string' => 'строка',
            'integer' => 'целое число',
            'number' => 'число',
            'boolean' => 'логическое значение',
            'null' => 'пустое значение',
            default => $type,
        };
    }

    private function contents(): string
    {
        $contents = @file_get_contents($this->path());
        if (false === $contents) {
            throw new \RuntimeException(\sprintf('Не удалось прочитать схему импорта "%s".', $this->path()));
        }

        return $contents;
    }

    private function schemaObject(): \stdClass
    {
        if (null === $this->schemaObject) {
            /** @var \stdClass $decoded */
            $decoded = json_decode($this->contents());
            $this->schemaObject = $decoded;
        }

        return $this->schemaObject;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeSchema(string $text): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

}
