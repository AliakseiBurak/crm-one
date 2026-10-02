<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Call;
use App\Entity\Contact;
use App\Entity\Enum\UserRole;
use App\Entity\ImportRun;
use App\Entity\Organization;
use App\Entity\User;
use App\Service\Import\CsvParser;
use App\Service\Import\ImportFileStorage;
use App\Tests\DatabaseWebTestCase;
use App\Tests\Fixtures\ImportCsvFixture;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Вкладка «JSON», LLM-компонент и второй формат источника прогона (change
 * add-organizations-json-import).
 *
 * Каждый тест создаёт свой прогон и организации, которые удаляет в конце: общая
 * база не должна меняться между прогонами.
 */
final class ImportJsonControllerTest extends DatabaseWebTestCase
{
    use ImportReviewFormTrait;

    private ImportFileStorage $storage;

    private CsvParser $parser;

    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Своё хранилище в отдельном каталоге: файлы прогонов этого теста не
        // должны попасть в рабочее `var/storage/imports`.
        $container = static::getContainer();
        $this->projectDir = sys_get_temp_dir() . '/import-json-storage-test-' . bin2hex(random_bytes(6));
        $this->storage = new ImportFileStorage($this->projectDir);
        $container->set(ImportFileStorage::class, $this->storage);
        $this->parser = $container->get(CsvParser::class);

        $this->login($this->makeUser('admin-json', 'admin-json@b2b-crm.loc', UserRole::Admin));
    }

    #[Test]
    public function theImportPageOffersEveryTab(): void
    {
        // Пять вкладок, первая — «Результаты» (change
        // add-organizations-json-import).
        $labels = [];
        foreach (['/admin/import/results', '/admin/import', '/admin/import/json', '/admin/import/llm', '/admin/import/prompt'] as $url) {
            $crawler = $this->open($url);
            $this->assertResponseIsSuccessful();
            $active = $crawler->filter('.tabs__item--active');
            self::assertCount(1, $active);
            $labels[] = trim($active->text());
        }
        self::assertSame(['Результаты', 'CSV', 'JSON', 'LLM', 'Промпт'], $labels);

        // Переход между вкладками — переход по адресу, а не показ и скрытие
        // блоков на месте.
        $this->open('/admin/import');
        $this->assertSelectorExists('a.tabs__item[href$="/admin/import/json"]');
        $this->assertSelectorExists('a.tabs__item[href$="/admin/import/results"]');
    }

    #[Test]
    public function theJsonTabIsOnlyAFileField(): void
    {
        $this->open('/admin/import/json');

        $this->assertResponseIsSuccessful();
        $this->assertPageContains('Загрузить JSON');
        self::assertSame(1, $this->countXPath('//input[@type="file"][@name="file"]'));
        self::assertSame(1, $this->countXPath('//button[contains(text(), "Загрузить JSON")]'));

        // Вставки на вкладке нет: ответ приходит файлом — из вкладки «LLM» или
        // из любого другого источника.
        self::assertSame(0, $this->countXPath('//textarea'));
        self::assertSame(0, $this->countXPath('//input[@name="payload"]'));
        // Описание формата и кнопка скачивания схемы — на вкладке «Промпт»,
        // а не здесь: здесь только форма загрузки.
        self::assertSame(0, $this->countXPath('//a[contains(text(), "Скачать JSON-схему")]'));
        self::assertSame(0, $this->countXPath('//h2'));

        // На вкладке «Промпт» — промпт, описание формата и схема.
        $this->open('/admin/import/prompt');
        $this->assertResponseIsSuccessful();
        self::assertSame(1, $this->countXPath('//pre/code'));
        $this->assertPageContains('organizations');
        $this->assertPageContains('21.10.25');
        self::assertSame(1, $this->countXPath(
            '//a[contains(@class, "btn--sm") and contains(text(), "Скачать JSON-схему")]',
        ));
    }

    #[Test]
    public function theResultsTabShowsTheRunsOfEveryFormat(): void
    {
        $this->createRun(2);
        $this->submitJson($this->payload(['ИзОтвета']));
        $this->assertResponseRedirects('/admin/import/results');

        // Таблица показывает имена файлов, а не названия организаций, поэтому
        // различаем прогоны по именам.
        $this->open('/admin/import/results');
        self::assertSame(1, $this->countXPath('//tr[contains(., "импорт.csv")]'));
        self::assertSame(1, $this->countXPath('//tr[contains(., "ответ-модели.json")]'));

        // На вкладках форматов списка нет: вкладка отвечает за то, как строка
        // получена, а не за состояние импорта.
        foreach (['/admin/import', '/admin/import/json', '/admin/import/llm', '/admin/import/prompt'] as $url) {
            $this->open($url);
            self::assertSame(0, $this->countXPath('//tr[contains(., "импорт.csv")]'), $url);
            self::assertSame(0, $this->countXPath('//tr[contains(., "ответ-модели.json")]'), $url);
        }

        $this->deleteOrganizations(['ИзОтвета']);
        $this->deleteRuns();
    }

    #[Test]
    public function aManagerCannotOpenTheJsonTab(): void
    {
        $this->login($this->makeUser('manager-json', 'manager-json@b2b-crm.loc', UserRole::Manager));

        $this->open('/admin/import/json');

        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function theSchemaIsServedAsADownloadableAttachment(): void
    {
        $this->client->request('GET', '/admin/import/json-schema');

        $this->assertResponseIsSuccessful();
        $disposition = (string) $this->client->getResponse()->headers->get('Content-Disposition');
        self::assertStringContainsString('attachment', $disposition);
        self::assertStringContainsString('organization-import.schema.json', $disposition);

        // `BinaryFileResponse` отдаёт файл, а не тело ответа: проверяется именно
        // то, что ушло бы в браузер.
        $file = $this->client->getResponse()->getFile();
        self::assertNotNull($file);
        $downloaded = (string) file_get_contents($file->getPathname());
        $schema = json_decode($downloaded, false, 512, JSON_THROW_ON_ERROR);

        // Скачанный документ — тот же контракт, по которому проверяется ответ.
        self::assertSame('object', $schema->type);
        self::assertTrue(isset($schema->properties->organizations));
        self::assertContains('organizations', $schema->required);
    }

    #[Test]
    public function uploadingAnAnswerCreatesARunWithoutStartingTheImport(): void
    {
        $this->open('/admin/import/json');
        $this->submitJson($this->payload(['АбесТрейд', 'Нафтан']));

        // Ответ сохранён и прогон появился в списке, но пакет для проверки не
        // формируется: его запускает «Импортировать», как и на вкладке CSV.
        $this->assertResponseRedirects('/admin/import/results');
        $this->client->followRedirect();

        $this->assertPageContains('ответ-модели.json');

        $this->em()->clear();
        $run = $this->singleRun();
        self::assertSame(ImportRun::SOURCE_FORMAT_JSON, $run->sourceFormat);
        self::assertSame(2, $run->totalRows);
        self::assertSame(0, $run->processedRows);
        // Имя прогона — имя загруженного файла: своё имя придумывать незачем,
        // ответ приходит из вкладки «LLM» уже под именем.
        self::assertSame('ответ-модели.json', $run->filename);
        self::assertNull($this->findOrganization('АбесТрейд'));

        // Файл ответа сохранён дословно: он понадобится для разбора и для отчёта
        // о замене.
        self::assertSame($this->payload(['АбесТрейд', 'Нафтан']), $this->storage->read($run->storageKey));

        $this->deleteRuns();
    }

    #[Test]
    public function uploadingTheSameAnswerCreatesAnIdenticalRun(): void
    {
        $this->open('/admin/import/json');
        $this->client->request('POST', '/admin/import/json', [
            '_csrf_token' => $this->csrfToken('/admin/import/json'),
        ], [
            'file' => $this->uploadedJson($this->payload(['АбесТрейд']), 'ответ-модели.json'),
        ]);

        $this->assertResponseRedirects('/admin/import/results');
        $this->em()->clear();

        $run = $this->singleRun();
        self::assertSame(ImportRun::SOURCE_FORMAT_JSON, $run->sourceFormat);
        self::assertSame('ответ-модели.json', $run->filename);
        self::assertSame(1, $run->totalRows);

        $this->deleteRuns();
    }

    #[Test]
    public function aPastedAnswerIsCheckedByTheSameSchemaAsAFile(): void
    {
        // Нарушение в файле и вставке отвергается одинаково, с номером
        // организации и названием поля.
        $broken = '{"organizations":[{"name":"Первая"},{"name":"","contacts":[{"name":"X","phone":"' . str_repeat('9', 40) . '"}]}]}';

        $this->open('/admin/import/json');
        $this->submitJson($broken);

        self::assertResponseStatusCodeSame(422);
        $this->assertPageContains('Прогон не создан');
        $this->assertPageContains('№2');
        $this->assertPageContains('contacts[0].phone');
        $this->assertPageContains('name');
        self::assertCount(0, $this->runs());
    }

    #[Test]
    public function malformedJsonIsRefusedAndNoRunIsCreated(): void
    {
        $this->open('/admin/import/json');
        $this->submitJson('{не json');

        self::assertResponseStatusCodeSame(422);
        $this->assertPageContains('не является корректным JSON');
        self::assertCount(0, $this->runs());
        // Править нечего: файл остаётся у администратора, загрузка его
        // повторяется после правки.
        self::assertSame(0, $this->countXPath('//textarea'));
    }

    #[Test]
    public function anEmptyOrganizationsArrayIsRefused(): void
    {
        $this->open('/admin/import/json');
        $this->submitJson('{"organizations":[]}');

        self::assertResponseStatusCodeSame(422);
        $this->assertPageContains('organizations');
        self::assertCount(0, $this->runs());
    }

    #[Test]
    public function aDateWithoutAYearIsRefusedInsteadOfGuessed(): void
    {
        // Год не подставляется и запись не прячется в заметку соседнего звонка:
        // ответ отвергается целиком.
        $this->open('/admin/import/json');
        $this->submitJson('{"organizations":[{"name":"АбесТрейд","calls":[{"date":"31.08","notes":"Договорённость"}]}]}');

        self::assertResponseStatusCodeSame(422);
        $this->assertPageContains('calls[0].date');
        self::assertCount(0, $this->runs());
    }

    #[Test]
    public function anAnswerIsAcceptedWhileAnotherRunIsUnfinished(): void
    {
        $this->createRun(5);

        $this->open('/admin/import/json');
        $this->submitJson($this->payload(['АбесТрейд']));

        // Прогоны независимы: незавершённый импорт не блокирует загрузку
        // ответа (design D12).
        $this->assertResponseRedirects('/admin/import/results');
        $this->em()->clear();
        self::assertCount(2, $this->runs());

        $this->deleteRuns();
    }

    #[Test]
    public function theImportButtonStartsTheImportOfAJsonRun(): void
    {
        $this->open('/admin/import/json');
        $this->submitJson($this->payload(['АбесТрейд', 'Нафтан', 'Белкарт']));

        $this->em()->clear();
        $run = $this->singleRun();

        $this->open('/admin/import/' . $run->id);

        $this->assertResponseIsSuccessful();
        $this->assertPageContains('АбесТрейд');
        $this->assertPageContains('Нафтан');
        $this->assertPageContains('Белкарт');

        $this->approvePackage();

        $this->em()->clear();
        self::assertSame(3, $this->singleRun()->processedRows);
        self::assertNotNull($this->findOrganization('АбесТрейд'));

        $this->deleteOrganizations(['АбесТрейд', 'Нафтан', 'Белкарт']);
        $this->deleteRuns();
    }

    #[Test]
    public function anAnswerCarryingUnpAndAnnualPlanIsAcceptedAndSaved(): void
    {
        // Файл из другого источника приносит эти поля настоящими, и отвергать
        // такой ответ целиком было неверно: они сохраняются и видны в проверке.
        $payload = json_encode(['organizations' => [[
            'name' => 'Нафтан',
            'unp' => '191000000',
            'annualPlan' => 'подписать договор в декабре',
        ]]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $this->open('/admin/import/json');
        $this->submitJson($payload);

        $this->em()->clear();
        $run = $this->singleRun();
        self::assertSame(1, $run->totalRows);

        $this->open('/admin/import/' . $run->id);
        self::assertSelectorExists('input[name="rows[1][unp]"][value="191000000"]');
        self::assertSelectorExists('input[name="rows[1][annualPlan]"][value="подписать договор в декабре"]');

        $this->approvePackage();

        $this->em()->clear();
        $organization = $this->findOrganization('Нафтан');
        self::assertNotNull($organization);
        self::assertSame('191000000', $organization->unp);
        self::assertSame('подписать договор в декабре', $organization->annualPlan);

        $this->deleteOrganizations(['Нафтан']);
        $this->deleteRuns();
    }

    #[Test]
    public function aListOfOnlyNamesCreatesOrganizations(): void
    {
        // У организации обязательно только название: такой ответ — полноценный
        // импорт, а не «почти пустой».
        $this->open('/admin/import/json');
        $this->submitJson($this->namesOnly(['Только название', 'Ещё одно название']));

        $this->em()->clear();
        $run = $this->singleRun();
        self::assertSame(2, $run->totalRows);

        $this->open('/admin/import/' . $run->id);
        // Отрасль, город, УНП и годовой план пусты, но видны и правятся.
        self::assertSelectorExists('input[name="rows[1][industry]"][value=""]');
        self::assertSelectorExists('input[name="rows[1][unp]"][value=""]');
        $this->approvePackage();

        $this->em()->clear();
        foreach (['Только название', 'Ещё одно название'] as $name) {
            $organization = $this->findOrganization($name);
            self::assertNotNull($organization, $name);
            self::assertNull($organization->industry);
            self::assertNull($organization->city);
            self::assertNull($organization->unp);
        }

        $this->deleteOrganizations(['Только название', 'Ещё одно название']);
        $this->deleteRuns();
    }

    #[Test]
    public function theMainMarkFromTheAnswerReachesTheContact(): void
    {
        $payload = $this->payload(['АбесТрейд']);

        $this->open('/admin/import/json');
        $this->submitJson($payload);

        $this->em()->clear();
        $run = $this->singleRun();
        $this->open('/admin/import/' . $run->id);

        // Отметка приходит из ответа и показывается уже отмеченной: она видна
        // до сохранения, а не появляется в базе исподтишка.
        self::assertSelectorExists('input[name="rows[1][contacts][0][isMain]"][value="1"][checked]');

        $this->approvePackage();

        $this->em()->clear();
        $organization = $this->findOrganization('АбесТрейд');
        self::assertNotNull($organization);
        self::assertTrue($organization->contacts->first()->isMain);

        $this->deleteOrganizations(['АбесТрейд']);
        $this->deleteRuns();
    }

    #[Test]
    public function aJsonPackageShowsIndustryAndCityAndSavesThem(): void
    {
        $this->open('/admin/import/json');
        $this->submitJson($this->payload(['АбесТрейд'], industry: 'ИТ-дистрибьютор', city: 'Минск'));

        $this->em()->clear();
        $run = $this->singleRun();
        $this->open('/admin/import/' . $run->id);

        // Поля, которые модель приносит сама, видны и правятся: выдуманное
        // значение иначе ушло бы в базу вслепую.
        $this->assertPageContains('Отрасль');
        $this->assertPageContains('Город');
        $this->assertPageContains('УНП');
        $this->assertPageContains('Годовой план');
        self::assertSelectorExists('input[name="rows[1][industry]"][value="ИТ-дистрибьютор"]');
        self::assertSelectorExists('input[name="rows[1][city]"][value="Минск"]');
        self::assertSelectorExists('input[name="rows[1][unp]"][value="191000000"]');

        $this->approvePackage([1 => ['industry' => 'Оптовая торговля', 'city' => 'Брест']]);

        $this->em()->clear();
        $organization = $this->findOrganization('АбесТрейд');
        self::assertNotNull($organization);
        // Исправленные значения сохраняются: проверка — это место, где человек
        // решает, во что превратится ответ модели.
        self::assertSame('Оптовая торговля', $organization->industry);
        self::assertSame('Брест', $organization->city);

        $this->deleteOrganizations(['АбесТрейд']);
        $this->deleteRuns();
    }

    #[Test]
    public function aCsvPackageStillHasNoCityField(): void
    {
        $run = $this->createRun(3);

        $this->open('/admin/import/' . $run->id);

        // Запрет родительского изменения остаётся в силе для прогонов из
        // CSV: городу в источнике взяться неоткуда.
        $this->assertPageNotContains('Отрасль');
        $this->assertPageNotContains('>Город<');
        self::assertSelectorNotExists('input[name="rows[1][city]"]');
        self::assertSelectorNotExists('input[name="rows[1][industry]"]');
        self::assertSelectorNotExists('input[name="rows[1][unp]"]');
        self::assertSelectorNotExists('input[name="rows[1][annualPlan]"]');

        $this->deleteOrganizations(['Строка 1', 'Строка 2', 'Строка 3']);
        $this->deleteRuns();
    }

    #[Test]
    public function aCsvRunCannotAcquireACityFromTamperedFormFields(): void
    {
        $run = $this->createRun(1);

        $this->open('/admin/import/' . $run->id);
        $this->approvePackage([1 => [
            'industry' => 'Подставленная отрасль',
            'city' => 'Подставленный город',
        ]]);

        $this->em()->clear();
        $organization = $this->findOrganization('Строка 1');
        self::assertNotNull($organization);
        // Формы у CSV-прогона таких полей не содержат, поэтому подставленное
        // значение не доходит до организации.
        self::assertNull($organization->industry);
        self::assertNull($organization->city);

        $this->deleteOrganizations(['Строка 1']);
        $this->deleteRuns();
    }

    #[Test]
    public function aJsonRunParsesContactsAndCallsAndMarksAPlannedCall(): void
    {
        $payload = json_encode(['organizations' => [[
            'name' => 'АбесТрейд',
            'industry' => 'ИТ-дистрибьютор',
            'city' => 'Минск',
            'contacts' => [[
                'name' => 'Вячеслав',
                'position' => 'начальник отдела обучения',
                'phone' => '+375339027636',
                'email' => 'V.Zakrevsky@naftan.by',
            ]],
            'calls' => [['date' => '29.05.2026', 'notes' => 'Направила КП']],
            'nextCall' => ['date' => '08.06.2026', 'purpose' => 'созвониться по КП'],
        ]]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $this->open('/admin/import/json');
        $this->submitJson($payload);

        $this->em()->clear();
        $run = $this->singleRun();
        $this->open('/admin/import/' . $run->id);
        $this->assertPageContains('Вячеслав');
        // Плановый контакт виден как таковой, а не как совершённый звонок.
        $this->assertPageContains('Планируемый');
        $this->approvePackage();

        $this->em()->clear();
        $organization = $this->findOrganization('АбесТрейд');
        self::assertNotNull($organization);

        // У звонков организации нет обратной коллекции, поэтому берём их
        // напрямую по организации, а не через `$organization->calls`.
        $contact = $this->em()->getRepository(Contact::class)->findOneBy(['organization' => $organization]);
        self::assertInstanceOf(Contact::class, $contact);
        self::assertSame('Вячеслав', $contact->name);
        self::assertSame('+375339027636', $contact->phone);
        self::assertSame('начальник отдела обучения', $contact->position);

        /** @var Call $made */
        $made = $this->em()->getRepository(Call::class)->findOneBy(['organization' => $organization, 'madeAt' => self::at('2026-05-29')]);
        self::assertInstanceOf(Call::class, $made);
        self::assertSame('2026-05-29', $made->madeAt?->format('Y-m-d'));

        /** @var Call $planned */
        $planned = $this->em()->getRepository(Call::class)->findOneBy(['organization' => $organization, 'scheduledAt' => self::at('2026-06-08')]);
        self::assertInstanceOf(Call::class, $planned);
        self::assertNull($planned->madeAt);
        self::assertSame('созвониться по КП', $planned->notes);

        $this->deleteOrganizations(['АбесТрейд']);
        $this->deleteRuns();
    }

    #[Test]
    public function theSourceFormatDecidesHowARunIsReadWhenReopened(): void
    {
        // Незавершённый прогон в системе ровно один, поэтому прогон другого
        // формата создаётся после того, как первый закончен: иначе страница
        // первого перенаправит на второй (design D9).
        $jsonRun = $this->submitJsonRun($this->payload(['ИзОтвета']));

        $this->open('/admin/import/' . $jsonRun->id);
        $this->assertPageContains('ИзОтвета');
        $this->assertPageContains('Отрасль');
        $this->approvePackageRun($jsonRun->id);

        $csvRun = $this->createRun(2);

        $this->open('/admin/import/' . $csvRun->id);
        $this->assertPageContains('Строка 1');
        $this->assertPageNotContains('Отрасль');
        $this->approvePackageRun($csvRun->id);

        $this->em()->clear();
        self::assertNotNull($this->findOrganization('ИзОтвета'));
        self::assertNotNull($this->findOrganization('Строка 1'));

        $this->deleteOrganizations(['ИзОтвета', 'Строка 1', 'Строка 2']);
        $this->deleteRuns();
    }

    #[Test]
    public function replacingAJsonRunComparesRowsStructurally(): void
    {
        $run = $this->submitJsonRun($this->payload(['АбесТрейд', 'Нафтан']));
        $this->markProcessed($run, 1);

        // Тот же ответ, записанный иначе: перестановка ключей и другая запись
        // payload — это не изменение данных, и отчёт обязан быть пустым.
        $reordered = '{"organizations":[{"calls":[],"contacts":[{"name":"Иван Петров",'
            . '"phone":"+375171234567","isMain":true}],"city":"Минск","industry":"ИТ-дистрибьютор",'
            . '"name":"АбесТрейд","unp":"191000000"},'
            . '{"contacts":[{"name":"Иван Петров","phone":"+375171234567","isMain":true}],'
            . '"city":"Минск","industry":"ИТ-дистрибьютор","name":"Нафтан","unp":"191000000"}]}';

        $this->open('/admin/import/' . $run->id . '/replace');
        $this->submitReplacement($reordered);

        // Числа в отчёте — организации, а не записи CSV, а строка продолжения
        // указывает на организацию нового файла.
        $this->assertPageContains('Организаций в текущем файле');
        $this->assertPageContains('Организаций в новом файле');
        $this->assertPageContains('Изменившиеся организации');
        $this->assertPageContains('нет');
        $this->assertPageContains('2 — «Нафтан»');

        // Прогон не тронут до подтверждения.
        $this->em()->clear();
        self::assertSame(1, $this->singleRun()->processedRows);
        self::assertSame(2, $this->singleRun()->totalRows);

        $this->deleteOrganizations(['АбесТрейд', 'Нафтан']);
        $this->deleteRuns();
    }

    #[Test]
    public function aChangedFieldInsideTheProcessedPrefixIsReported(): void
    {
        $run = $this->submitJsonRun($this->payload(['АбесТрейд', 'Нафтан']));
        $this->markProcessed($run, 1);

        $this->open('/admin/import/' . $run->id . '/replace');
        $this->submitReplacement($this->payload(['АбесТрейд изменён', 'Нафтан']));

        $this->assertPageContains('Изменившиеся организации');
        $this->assertPageContains('1');

        // Уже обработанный префикс остаётся замороженным: правка в отчёте — это
        // сообщение, а не запрет, и содержимое там осталось прежним.
        $this->deleteOrganizations(['АбесТрейд', 'Нафтан']);
        $this->deleteRuns();
    }

    #[Test]
    public function aConfirmedReplacementSwapsTheFileAndDeletesThePreviousOne(): void
    {
        $run = $this->submitJsonRun($this->payload(['АбесТрейд', 'Нафтан']));
        $this->markProcessed($run, 1);
        $previousKey = $run->storageKey;

        $this->open('/admin/import/' . $run->id . '/replace');
        $this->submitReplacement($this->payload(['АбесТрейд', 'Нафтан', 'Белкарт']));

        $this->confirmReplacement();

        $this->em()->clear();
        $reloaded = $this->singleRun();
        // `totalRows` становится новым числом организаций, `processedRows`
        // сохраняется: обработанный префикс не переигрывается.
        self::assertSame(3, $reloaded->totalRows);
        self::assertSame(1, $reloaded->processedRows);
        // Имя прогона остаётся прежним: замена меняет файл и счётчики, а не то,
        // как прогон называется.
        self::assertSame('ответ-модели.json', $reloaded->filename);
        // Прежний файл удаляется только здесь — единственное место, где поток
        // импорта удаляет файл.
        self::assertFileDoesNotExist($this->storage->path($previousKey));

        $this->deleteRuns();
    }

    #[Test]
    public function anInvalidReplacementNeverReachesTheReport(): void
    {
        $run = $this->submitJsonRun($this->payload(['АбесТрейд']));
        $runId = $run->id;
        $this->client->request('POST', '/admin/import/' . $runId . '/replace', [
            '_csrf_token' => $this->csrfToken('/admin/import/' . $runId . '/replace'),
            'payload' => '{"organizations":[{"name":""}]}',
        ]);

        // Непрошедший кандидат не доходит до отчёта: прогон остаётся прежним.
        $this->assertResponseRedirects('/admin/import/' . $runId);
        $this->client->followRedirect();
        $this->assertPageContains('name');

        $this->em()->clear();
        self::assertSame(1, $this->singleRun()->totalRows);

        $this->deleteRuns();
    }

    #[Test]
    public function aShorterAnswerIsConfirmedWithAWarningAndCompletesTheRun(): void
    {
        $run = $this->submitJsonRun($this->payload(['АбесТрейд', 'Нафтан', 'Белкарт']));
        $this->markProcessed($run, 2);

        $this->open('/admin/import/' . $run->id . '/replace');
        $this->submitReplacement($this->payload(['АбесТрейд']));

        // Ответ короче уже обработанной части не отвергается: это осознанное
        // решение администратора, и импорт после замены считается завершённым.
        $this->assertPageContains('считаться завершённым');

        $this->confirmReplacement();

        $this->em()->clear();
        $reloaded = $this->singleRun();
        self::assertSame(1, $reloaded->totalRows);
        self::assertSame(2, $reloaded->processedRows);

        $this->deleteRuns();
    }

    #[Test]
    public function cancellingAJsonReplacementKeepsTheCurrentFile(): void
    {
        $run = $this->submitJsonRun($this->payload(['АбесТрейд']));
        $runId = $run->id;

        $this->open('/admin/import/' . $runId . '/replace');
        $this->submitReplacement($this->payload(['АбесТрейд', 'Нафтан']));

        $url = $this->candidateUrl();
        $this->client->request('POST', $url, [
            '_csrf_token' => $this->csrfToken($url),
            'action' => 'cancel',
        ]);

        $this->assertResponseRedirects('/admin/import/' . $runId);
        $this->em()->clear();
        $reloaded = $this->singleRun();
        // Прогон продолжает работать с прежним файлом, а кандидат удалён как
        // невостребованный.
        self::assertSame(1, $reloaded->totalRows);
        self::assertSame(0, $reloaded->processedRows);
        self::assertSame($run->storageKey, $reloaded->storageKey);

        $this->deleteRuns();
    }

    #[Test]
    public function aReplacementFormOffersBothInputPathsForAJsonRun(): void
    {
        $run = $this->submitJsonRun($this->payload(['АбесТрейд']));

        $this->open('/admin/import/' . $run->id . '/replace');

        $this->assertResponseIsSuccessful();
        self::assertSelectorExists('textarea[name="payload"]');
        self::assertSelectorExists('input[type="file"][name="file"]');

        $this->deleteRuns();
    }

    #[Test]
    public function aCsvReplacementFormStillAsksForAFileOnly(): void
    {
        $run = $this->createRun(3);

        $this->open('/admin/import/' . $run->id . '/replace');

        $this->assertResponseIsSuccessful();
        self::assertSelectorNotExists('textarea[name="payload"]');
        self::assertSelectorExists('input[type="file"][name="file"]');

        $this->deleteRuns();
    }

    #[Test]
    public function theLlmTabIsAdminOnlyAndCarriesTheSourceFieldAndTheWarning(): void
    {
        $this->open('/admin/import/llm');

        $this->assertResponseIsSuccessful();
        // Ключ виден браузеру и тому, кто у рабочей станции, — это сказано
        // прямо и стоит под самым полем, где ключ и вводится.
        $this->assertPageContains('Ключ хранится только в этой вкладке браузера');
        self::assertPageOrder('API-ключ', 'Ключ хранится только в этой вкладке браузера');
        self::assertSelectorExists('#llm-provider');
        self::assertSelectorExists('#llm-key');
        self::assertSelectorExists('#llm-models');
        self::assertSelectorExists('#llm-send');

        // У полей формы нет атрибута `name`. Форма никогда не отправляется —
        // запрос уходит из браузера провайдеру, — но с именем у поля ключ
        // подставился бы в адрес приложения при отправке без клиента.
        self::assertSame(0, $this->countXPath('//form[@id="llm-form"]//*[@name]'));

        // Отдельного действия «забыть ключ» нет: ключ исчезает с вкладкой, и
        // вторая кнопка, означающая то же самое, только мешает.
        self::assertSelectorNotExists('#llm-forget');

        // Исходный текст — то, что разбирается, а не промпт: промпт отправляет
        // вкладка сама, а текст вводит администратор.
        self::assertSelectorExists('#llm-source');
        $this->assertPageOrder('llm-source', 'llm-provider');
        self::assertSame(1, $this->countXPath('//button[contains(text(), "Составить JSON")]'));

        // Промпта и кнопки скачивания схемы на вкладке модели нет: они на
        // вкладке «Промпт», чтобы страница не пересказывала сама себя.
        self::assertSame(0, $this->countXPath('//a[contains(text(), "Скачать JSON-схему")]'));

        // Список моделей выключен, пока модели не получены: выбрать из пустого
        // списка нельзя. «Получить модели» — над списком, а не под ним.
        self::assertSelectorExists('#llm-model[disabled]');
        $this->assertPageOrder('Получить модели', 'Модель');

        // Клиент получает проекцию того же документа, по которому сервер
        // проверяет ответ. Проекция — корень схемы: проекция свойства
        // `organizations` — это схема массива, и провайдер по ней отвечает
        // голым массивом, который импорт не принимает.
        $this->assertPageContains('ORGANIZATION_IMPORT_SCHEMA');
        $format = $this->schemaSentToClient();
        self::assertSame('object', $format['type']);
        self::assertSame(['organizations'], $format['required']);
        self::assertSame(['organizations'], array_keys($format['properties']));
        self::assertSame('array', $format['properties']['organizations']['type']);

        $organization = $format['properties']['organizations']['items'];
        self::assertSame(['name'], $organization['required']);
        self::assertArrayHasKey('nextCall', $organization['properties']);
        self::assertArrayHasKey('unp', $organization['properties']);

        // Ключевые слова, которые движок структурированного вывода не понимает,
        // убраны: с ними Ollama отвечает отказом разбора грамматики. Проверку
        // длины и дат сервер всё равно делает у себя по полному документу.
        $encoded = json_encode($format, JSON_THROW_ON_ERROR);
        foreach (['$ref', '$id', '$schema', 'pattern', 'maxLength', 'additionalProperties'] as $keyword) {
            self::assertStringNotContainsString('"' . $keyword . '"', $encoded);
        }

        $this->client->restart();
        $this->login($this->makeUser('manager-llm', 'manager-llm@b2b-crm.loc', UserRole::Manager));
        $this->open('/admin/import/llm');
        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function theJsonAddressesAreNotReadAsRunIdentifiers(): void
    {
        // `/admin/import/{id}` уже занят проверкой пакета; без требования к `id`
        // все три новых адреса отвечали бы 404, и ошибка выглядела бы
        // «действие не найдено» вместо коллизии маршрутов.
        $this->open('/admin/import/json');
        $this->assertResponseIsSuccessful();

        $this->open('/admin/import/llm');
        $this->assertResponseIsSuccessful();

        $this->client->request('GET', '/admin/import/json-schema');
        $this->assertResponseIsSuccessful();
    }

    /**
     * Ответ уходит файлом: вкладка JSON не принимает вставку — ответ приходит
     * к администратору из вкладки «LLM» файлом и загружается оттуда.
     */
    private function submitJson(string $payload, string $filename = 'ответ-модели.json'): void
    {
        $this->client->request('POST', '/admin/import/json', [
            '_csrf_token' => $this->csrfToken('/admin/import/json'),
        ], [
            'file' => $this->uploadedJson($payload, $filename),
        ]);
    }

    private function submitJsonRun(string $payload): ImportRun
    {
        $this->submitJson($payload);
        $this->assertResponseRedirects('/admin/import/results');
        $this->em()->clear();

        return $this->singleRun();
    }

    /**
     * Отправка замены и переход на страницу отчёта: кандидат сохраняется, адрес
     * становится закладкой, и тест дальше работает именно с ней.
     */
    private function submitReplacement(string $payload): void
    {
        $runId = $this->singleRun()->id;
        $this->client->request('POST', '/admin/import/' . $runId . '/replace', [
            '_csrf_token' => $this->csrfToken('/admin/import/' . $runId . '/replace'),
            'payload' => $payload,
        ]);

        // Кандидат едет в адресе: без этого отчёт не был бы воспроизводим.
        $this->assertResponseRedirects();
        $candidate = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('candidate=', $candidate);

        $this->open($candidate);
        $this->assertResponseIsSuccessful();
    }

    private function candidateUrl(): string
    {
        return (string) $this->client->getRequest()->getRequestUri();
    }

    /**
     * Подтверждение замены с той страницы отчёта, которая уже открыта.
     */
    private function confirmReplacement(): void
    {
        $url = $this->candidateUrl();
        $this->client->request('POST', $url, [
            '_csrf_token' => $this->csrfToken($url),
            'action' => 'confirm',
        ]);
    }

    /**
     * Утверждение пакета открытой страницы.
     *
     * @param array<int, array<string, mixed>> $overrides значения, подставленные поверх отрисованных
     */
    private function approvePackage(array $overrides = []): void
    {
        $this->approvePackageRun($this->singleRun()->id, $overrides);
    }

    /**
     * @param array<int, array<string, mixed>> $overrides
     */
    private function approvePackageRun(int $runId, array $overrides = []): void
    {
        $rows = [];
        foreach ($this->reviewRows() as $number => $values) {
            // `array_merge`, а не `+`: подставленное тестом значение должно
            // выигрывать у отрисованного, иначе исправление не проверялось бы.
            $rows[$number] = array_merge($values, $overrides[$number] ?? []);
        }

        self::assertNotSame([], $rows);

        $this->client->request('POST', '/admin/import/' . $runId . '/approve', [
            '_csrf_token' => $this->csrfToken('/admin/import/' . $runId),
            'rows' => $rows,
        ]);
    }

    /**
     * Число элементов по XPath.
     *
     * DomCrawler не понимает `:has-text`, а проверять надо именно текст кнопки:
     * «маленькая кнопка» и «кнопка справа от поля» — это про разметку, а не про
     * наличие элемента.
     */
    private function countXPath(string $xpath): int
    {
        return $this->client->getCrawler()->filterXPath($xpath)->count();
    }

    /**
     * Порядок двух фрагментов на странице: первый идёт раньше второго.
     */
    private function assertPageOrder(string $earlier, string $later): void
    {
        $content = $this->pageContent();
        $first = strpos($content, $earlier);
        $second = strpos($content, $later);
        self::assertIsInt($first, \sprintf('На странице нет «%s».', $earlier));
        self::assertIsInt($second, \sprintf('На странице нет «%s».', $later));
        self::assertLessThan($second, $first);
    }

    private function assertPageContains(string $needle): void
    {
        self::assertStringContainsString($needle, $this->pageContent());
    }

    private function assertPageNotContains(string $needle): void
    {
        self::assertStringNotContainsString($needle, $this->pageContent());
    }

    private function pageContent(): string
    {
        $content = (string) $this->client->getResponse()->getContent();
        self::assertIsString($content);

        return $content;
    }

    /**
     * Полдень даты в часовом поясе базы — так `ImportProcessor` сохраняет
     * разобранную дату, и искать звонок надо ровно этим значением.
     */
    private function at(string $date): \DateTimeImmutable
    {
        return (new \DateTimeImmutable($date))->setTime(12, 0);
    }

    /**
     * Документ, отданный клиенту в `window.ORGANIZATION_IMPORT_SCHEMA`.
     *
     * @return array<string, mixed>
     */
    private function schemaSentToClient(): array
    {
        self::assertSame(
            1,
            preg_match(
                '/window\.ORGANIZATION_IMPORT_SCHEMA = (\{.*?\});/s',
                $this->pageContent(),
                $matches,
            ),
            'Схема не отдана клиенту в объявленной переменной.',
        );

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(str_replace('\\/', '/', $matches[1]), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function csrfToken(string $pageUrl): string
    {
        $token = $this->open($pageUrl)->filter('input[name="_csrf_token"]')->first()->attr('value');
        self::assertIsString($token);

        return $token;
    }

    private function uploadedJson(string $contents, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'import-json-');
        self::assertIsString($path);
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, 'application/json', null, true);
    }

    /**
     * Ответ со списком названий и, по желанию, отраслью и городом.
     *
     * @param string[] $names
     */
    private function payload(array $names, string $industry = 'ИТ-дистрибьютор', string $city = 'Минск'): string
    {
        $organizations = [];
        foreach ($names as $name) {
            $organizations[] = [
                'name' => $name,
                'industry' => $industry,
                'city' => $city,
                'unp' => '191000000',
                'contacts' => [['name' => 'Иван Петров', 'phone' => '+375171234567', 'isMain' => true]],
                'calls' => [],
            ];
        }

        return json_encode(['organizations' => $organizations], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Ответ минимального содержания: у организации обязательно только название.
     *
     * @param string[] $names
     */
    private function namesOnly(array $names): string
    {
        $organizations = array_map(
            static fn(string $name): array => ['name' => $name],
            $names,
        );

        return json_encode(['organizations' => $organizations], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private function createRun(int $rows, int $processed = 0): ImportRun
    {
        $fixture = new ImportCsvFixture();
        for ($i = 1; $i <= $rows; ++$i) {
            $fixture->row('Строка ' . $i, '', '(0' . (1 + $i % 9) . '.0' . (1 + $i % 9) . '.2025) Звонок ' . $i);
        }

        $storageKey = $this->storage->storeContents($fixture->content());
        $run = (new ImportRun())
            ->setFilename('импорт.csv')
            ->setStorageKey($storageKey)
            ->setSourceFormat(ImportRun::SOURCE_FORMAT_CSV)
            ->setTotalRows($this->parser->countRecords($this->storage->path($storageKey)))
            ->setProcessedRows($processed);
        $this->em()->persist($run);
        $this->em()->flush();

        return $run;
    }

    private function makeUser(string $login, string $email, UserRole $role): User
    {
        $user = (new User())->setLogin($login)->setEmail($email)->setRole($role);
        $user->setPassword('test-password-hash');
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    /**
     * @return ImportRun[]
     */
    private function runs(): array
    {
        return $this->em()->getRepository(ImportRun::class)->findAll();
    }

    private function singleRun(): ImportRun
    {
        $runs = $this->runs();
        self::assertCount(1, $runs);

        return $runs[0];
    }

    private function findOrganization(string $name): ?Organization
    {
        return $this->em()->getRepository(Organization::class)->findOneBy(['name' => $name]);
    }

    private function deleteRuns(): void
    {
        foreach ($this->runs() as $run) {
            $this->storage->delete($run->storageKey);
            $this->em()->remove($run);
        }
        $this->em()->flush();
    }

    /**
     * Удаление созданных тестом организаций вместе с контактами и звонками.
     *
     * Через соединение, а не через `remove()`: после `clear()` сущностей в unit of
     * work нет, и удаление одной из них заканчивается исключением о несвязанном
     * графе объектов.
     *
     * @param string[] $names
     */
    private function deleteOrganizations(array $names): void
    {
        if ([] === $names) {
            return;
        }

        $connection = $this->em()->getConnection();
        $organizationIds = $connection->fetchFirstColumn(
            'SELECT id FROM organization WHERE name IN (?)',
            [$names],
            [\Doctrine\DBAL\ArrayParameterType::STRING],
        );
        if ([] === $organizationIds) {
            return;
        }

        $connection->executeStatement(
            'DELETE FROM organization WHERE id IN (?)',
            [$organizationIds],
            [\Doctrine\DBAL\ArrayParameterType::INTEGER],
        );
        foreach (['contact', 'call'] as $table) {
            $connection->executeStatement(
                \sprintf('DELETE FROM %s WHERE organization_id IN (?)', $table),
                [$organizationIds],
                [\Doctrine\DBAL\ArrayParameterType::INTEGER],
            );
        }
        $this->em()->clear();
    }

    /**
     * Прогон помечается частично обработанным без утверждения пакета: замена
     * файла доступна только пока `processedRows < totalRows`, а прогонять пакет
     * ради этого не нужно.
     */
    private function markProcessed(ImportRun $run, int $processed): void
    {
        $run->setProcessedRows($processed);
        $this->em()->flush();
    }
}
