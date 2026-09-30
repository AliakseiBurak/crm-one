<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Call;
use App\Entity\Contact;
use App\Entity\Enum\UserRole;
use App\Entity\ImportRun;
use App\Entity\Organization;
use App\Entity\OrgGroupMembership;
use App\Entity\User;
use App\Service\Import\CsvParser;
use App\Service\Import\ImportFileStorage;
use App\Tests\DatabaseWebTestCase;
use App\Tests\Fixtures\ImportCsvFixture;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Функциональные тесты ImportController (change add-organizations-csv-import).
 *
 * Каждый тест создаёт собственный прогон и свои данные и убирает их за собой:
 * общая база остаётся неизменной между прогонами.
 */
final class ImportControllerTest extends DatabaseWebTestCase
{
    /**
     * Индекс ячейки «Последняя обработанная строка» в строке списка:
     * 0 — файл, 1 — всего строк, 2 — обработано, 3 — создан, 4 — последняя
     * обработанная строка, 5 — действия.
     */
    private const int LAST_PROCESSED_COLUMN = 4;

    private ImportFileStorage $storage;

    private CsvParser $parser;

    private string $projectDir;

    /**
     * Хранилище файлов подменяется на временное: тест не должен оставлять
     * каталоги в var/storage рядом с рабочим приложением.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $container = static::getContainer();
        $this->projectDir = sys_get_temp_dir() . '/import-storage-test-' . bin2hex(random_bytes(6));
        $this->storage = new ImportFileStorage($this->projectDir);
        $container->set(ImportFileStorage::class, $this->storage);
        $this->parser = $container->get(CsvParser::class);
    }

    protected function tearDown(): void
    {
        $this->purgeImportData();
        (new Filesystem())->remove($this->projectDir);

        parent::tearDown();
    }

    public function testManagerIsRefused(): void
    {
        $this->login($this->makeUser('manager-import', 'manager-import@b2b-crm.loc', UserRole::Manager));

        $this->client->request('GET', '/admin/import');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testGuestIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/admin/import');

        $this->assertResponseRedirects('/login');
    }

    public function testImportListIsEmptyWithoutRuns(): void
    {
        $this->login($this->makeAdmin());

        $this->open('/admin/import');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Импорт организаций');
        $this->assertSelectorExists('.tabs__item--active');
        $this->assertSelectorTextContains('.tabs__item--active', 'CSV');
    }

    public function testUploadCreatesRunAndReturnsToTheList(): void
    {
        $this->login($this->makeAdmin());

        $this->open('/admin/import');
        $this->upload('valid.csv');

        $this->assertResponseRedirects('/admin/import');
        $run = $this->singleRun();
        $this->client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertPageContains($run->filename);
        $this->assertSelectorExists('a[href="/admin/import/' . $run->id . '/download"]');
    }

    public function testParsingDoesNotStartOnUpload(): void
    {
        $this->login($this->makeAdmin());

        $run = $this->uploadFixture('valid.csv');

        // Разбор записей и пакет для проверки не сформированы: до действия
        // «Импортировать» в списке их нет.
        $this->em()->clear();
        self::assertSame(0, $this->singleRun()->processedRows);
        self::assertSame([], $this->em()->getRepository(\App\Entity\Organization::class)->findBy(
            ['name' => 'Нафтан'],
        ));

        // А по кнопке — открывается пакет.
        $this->open('/admin/import/' . $run->id);
        $this->assertResponseIsSuccessful();
        self::assertCount(5, $this->client->getCrawler()->filter('.import-row'));
    }

    public function testDownloadServesTheRunFileUnderItsFilename(): void
    {
        $this->login($this->makeAdmin());
        $run = $this->uploadFixture('valid.csv');
        $expected = (string) file_get_contents($this->storage->path($run->storageKey));

        $this->open('/admin/import/' . $run->id . '/download');

        $this->assertResponseIsSuccessful();
        // BinaryFileResponse отдаётся потоком, поэтому сверяется сам файл
        // ответа, а не тело.
        $file = $this->client->getResponse()->getFile();
        self::assertNotNull($file);
        self::assertSame($expected, (string) file_get_contents($file->getPathname()));
        self::assertStringContainsString(
            'attachment',
            (string) $this->client->getResponse()->headers->get('Content-Disposition'),
        );
        self::assertStringContainsString(
            rawurlencode('valid.csv'),
            rawurldecode((string) $this->client->getResponse()->headers->get('Content-Disposition')),
        );
    }

    public function testTableComesBeforeTheUploadForm(): void
    {
        $this->login($this->makeAdmin());
        $this->createRun(3);

        $crawler = $this->open('/admin/import');

        $table = $crawler->filter('table')->first();
        self::assertGreaterThan(0, $table->count());
        $formAction = $crawler->filter('form[action$="/admin/import/upload"]');
        self::assertSame(1, $formAction->count());
        $form = $formAction->getNode(0);
        // Форма объявлена после таблицы в разметке.
        self::assertGreaterThan(
            $this->domPositionOf($table->getNode(0)),
            $this->domPositionOf($form),
            'Таблица прогонов должна идти перед формой загрузки.',
        );
        $this->assertPageNotContains('Загрузки');
    }

    /**
     * Позиция узла в разметке: сравнивается не DOM-путь, а позиция во
     * flattened-порядке, иначе сравнивать нечего.
     */
    private function domPositionOf(\DOMNode $node): int
    {
        $document = $node->ownerDocument;
        self::assertNotNull($document);

        $all = iterator_to_array($document->getElementsByTagName('*')->getIterator());
        foreach ($all as $index => $candidate) {
            if ($candidate === $node) {
                return $index;
            }
        }

        return PHP_INT_MAX;
    }

    public function testUploadIsRejectedWhileAnImportIsUnfinished(): void
    {
        $this->login($this->makeAdmin());

        $this->open('/admin/import');
        $this->upload('valid.csv');
        $this->client->followRedirect();

        $this->open('/admin/import');
        $this->upload('valid.csv');
        $this->client->followRedirect();

        $this->assertSelectorTextContains('.alert--error', 'не завершён');
        self::assertCount(1, $this->runs());
    }

    public function testUploadWithWrongHeadersIsRejectedWithExpectedHeaders(): void
    {
        $this->login($this->makeAdmin());

        $this->open('/admin/import');
        $this->upload('missing-column.csv');
        $this->client->followRedirect();

        $this->assertSelectorTextContains('.alert--error', '«Взаимодействия»');
        self::assertSame([], $this->runs());
    }

    public function testUploadWithoutDataRecordsIsRejected(): void
    {
        $this->login($this->makeAdmin());

        $this->open('/admin/import');
        $this->upload('headers-only.csv');
        $this->client->followRedirect();

        $this->assertSelectorTextContains('.alert--error', 'нет ни одной строки данных');
        self::assertSame([], $this->runs());
    }

    public function testReviewShowsAtMost20RowsAndDerivedProgress(): void
    {
        $this->login($this->makeAdmin());
        $run = $this->createRun(30);

        $this->open('/admin/import/' . $run->id);

        $this->assertResponseIsSuccessful();
        self::assertCount(20, $this->client->getCrawler()->filter('.import-row'));
        $this->assertSelectorTextContains('.import-progress', '0');
        $this->assertSelectorTextContains('.import-progress', '30');
    }

    public function testReviewChunkStartsAtFirstUnprocessedRowAndIgnoresClientPosition(): void
    {
        $this->login($this->makeAdmin());
        $run = $this->createRun(60);
        $run->setProcessedRows(10);
        $this->em()->flush();

        $this->open('/admin/import/' . $run->id);
        $first = $this->firstRowNumber();
        $chunk = $this->rowNumbers();

        self::assertSame(11, $first);
        self::assertCount(20, $chunk);

        // Тот же адрес — тот же пакет; присланная позиция его не сдвигает.
        $this->open('/admin/import/' . $run->id);
        self::assertSame($chunk, $this->rowNumbers());

        $this->open('/admin/import/' . $run->id . '?from=3');
        self::assertSame($chunk, $this->rowNumbers());
    }

    public function testReviewRedirectsToAnotherUnfinishedRun(): void
    {
        $this->login($this->makeAdmin());
        $finished = $this->createRun(2, processed: 2);
        $unfinished = $this->createRun(5);

        $this->open('/admin/import/' . $finished->id);

        $this->assertResponseRedirects('/admin/import/' . $unfinished->id);
    }

    public function testReviewOfFinishedRunRedirectsToListWithTotals(): void
    {
        $this->login($this->makeAdmin());
        $run = $this->createRun(2, processed: 2);

        $this->open('/admin/import/' . $run->id);

        $this->assertResponseRedirects('/admin/import');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert--success', 'Обработано строк в этом прогоне: 2');
        $this->assertSelectorTextContains('.alert--success', 'обработано всего: 2');
    }

    public function testApproveCreatesOrganizationsContactsAndCallsAndAdvancesProgress(): void
    {
        $this->login($this->makeAdmin());
        $run = $this->uploadFixture('valid.csv');

        $this->open('/admin/import/' . $run->id);
        $this->approve($run, $this->approvalFieldsFromPage());

        $this->assertResponseRedirects();

        $this->em()->clear();
        $organization = $this->findOrganization('Нафтан');
        self::assertNotNull($organization);
        self::assertSame('Курс по переговорам', $organization->coursesAttended);
        self::assertSame('https://armis.by/', $organization->website);
        self::assertStringStartsWith('Актуальный курс: ', (string) $organization->description);
        self::assertTrue($organization->isActive);
        self::assertFalse($organization->isOptedOut);
        self::assertNull($organization->unp);
        self::assertNull($organization->industry);
        self::assertNull($organization->city);
        self::assertNotNull($organization->createdBy);
        self::assertCount(0, $organization->groupMemberships);

        $contacts = $organization->contacts->toArray();
        self::assertGreaterThanOrEqual(1, \count($contacts));
        foreach ($contacts as $contact) {
            self::assertFalse($contact->isMain, 'Импорт не назначает основной контакт.');
        }
        self::assertSame('Иван Петров', $contacts[0]->name);

        $calls = $this->em()->getRepository(Call::class)->findBy(['organization' => $organization]);
        $made = array_values(array_filter($calls, static fn(Call $c): bool => null !== $c->madeAt));
        $planned = array_values(array_filter($calls, static fn(Call $c): bool => null !== $c->scheduledAt));
        self::assertGreaterThanOrEqual(1, \count($made));
        self::assertCount(1, $planned);
        self::assertSame('25.08.2026 12:00', $made[0]->madeAt?->format('d.m.Y H:i'));
        self::assertNotNull($made[0]->madeBy);
        self::assertFalse($made[0]->isDeal);
        self::assertFalse($made[0]->isRefusal);
        self::assertFalse($made[0]->isNoAnswer);
        self::assertNull($planned[0]->madeAt);
        self::assertNull($planned[0]->madeBy);
        self::assertSame('08.06.2026', $planned[0]->scheduledAt?->format('d.m.Y'));

        $this->em()->clear();
        self::assertSame(5, $this->singleRun()->processedRows);
    }

    public function testResubmittedApprovalFormInsertsNothingAndSkipsNoRow(): void
    {
        $this->login($this->makeAdmin());
        $run = $this->createRun(10);

        $this->open('/admin/import/' . $run->id);
        $staleForm = $this->approvalFieldsFromPage();
        $this->approve($run, $staleForm);

        $this->em()->clear();
        $organizationsAfterFirst = \count($this->em()->getRepository(Organization::class)->findAll());
        $processedAfterFirst = $this->singleRun()->processedRows;

        // Ровно та же форма, отправленная повторно. Счётчик — единственная
        // защита: сервер начинает с processedRows + 1 и пропускает всё, что не
        // выше счётчика.
        $this->approve($run, $staleForm);

        $this->em()->clear();
        self::assertSame($organizationsAfterFirst, \count($this->em()->getRepository(Organization::class)->findAll()));
        self::assertSame($processedAfterFirst, $this->singleRun()->processedRows);
    }

    public function testEmptyNameStopsThePackageAndKeepsEarlierRows(): void
    {
        $this->login($this->makeAdmin());
        $run = $this->createRun(20);

        $this->open('/admin/import/' . $run->id);
        $fields = $this->approvalFieldsFromPage();
        // 13-я строка пакета.
        $fields['rows'][13]['name'] = '';
        $this->approve($run, $fields);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('.alert--error', 'Строка 13');
        $this->assertSelectorTextContains('.alert--error', 'название организации не заполнено');

        $this->em()->clear();
        self::assertSame(12, $this->singleRun()->processedRows);
        self::assertNotNull($this->findOrganization('Строка 1'));
        self::assertNotNull($this->findOrganization('Строка 12'));
        self::assertNull($this->findOrganization('Строка 13'));
        self::assertNull($this->findOrganization('Строка 14'));

        // Пакет показан с остановившейся строки, и она же редактируется на месте.
        self::assertSame(13, $this->firstRowNumber());
        $this->assertSelectorExists('.import-row--stopped');
        $this->assertSelectorExists('.import-row--stopped input[name="rows[13][name]"]');
    }

    /**
     * Запись без данных организации и контактов исправлять нечем: она
     * пропускается сразу при открытии страницы пакета, в таблице её нет, а
     * пропуск назван отдельным уведомлением с номером строки.
     *
     * Запись без данных — это не обязательно полностью пустая строка файла:
     * такие отсекает ещё парсер, до пакета. Здесь запись непустая, но в ней
     * нет ни названия, ни контактов — только описание «Актуальный курс».
     */
    /**
     * У контакта в форме проверки есть заметки и отметка основного. Заметки
     * приходят из формы, отметка — тоже: колонки источника их не содержат.
     */
    public function testContactNotesAndMainMarkAreSavedFromTheForm(): void
    {
        $this->login($this->makeAdmin());
        $run = $this->createRunFromFixture(
            (new ImportCsvFixture())
                ->row('Первая', '', '(10.05.2025) Звонок', 'Иванов, тел: +375171234567, Петров, тел: +375291234567'),
        );

        $this->open('/admin/import/' . $run->id);
        $fields = $this->approvalFieldsFromPage();

        $fields['rows'][1]['contacts'][0]['notes'] = 'Звонить после обеда';
        $fields['rows'][1]['contacts'][1]['isMain'] = '1';

        $this->approve($run, $fields);

        $this->assertResponseRedirects();
        $this->em()->clear();
        $contacts = $this->em()->getRepository(Contact::class)->findAll();
        self::assertCount(2, $contacts);

        $byName = [];
        foreach ($contacts as $contact) {
            $byName[$contact->name] = $contact;
        }

        self::assertSame('Звонить после обеда', $byName['Иванов']->notes);
        self::assertFalse($byName['Иванов']->isMain);
        self::assertNull($byName['Петров']->notes);
        self::assertTrue($byName['Петров']->isMain, 'отмеченный контакт становится основным');
    }

    /**
     * Основной контакт в организации один, и колонки источника эту отметку не
     * различают: она может прийти только из формы проверки.
     */
    public function testMainMarkIsNotTakenFromTheFile(): void
    {
        $this->login($this->makeAdmin());
        $run = $this->createRunFromFixture(
            (new ImportCsvFixture())->row('Первая', '', '(10.05.2025) Звонок', 'Иванов, тел: +375171234567'),
        );

        $this->open('/admin/import/' . $run->id);
        $this->approve($run, $this->approvalFieldsFromPage());

        $this->assertResponseRedirects();
        $this->em()->clear();
        $contacts = $this->em()->getRepository(Contact::class)->findAll();
        self::assertCount(1, $contacts);
        self::assertFalse($contacts[0]->isMain);
    }

    /**
     * Слияние дополняет существующую организацию: отметка основного снимается с
     * её прежнего основного контакта, иначе у организации окажется два.
     */
    public function testMainMarkReplacesTheExistingOneOnMerge(): void
    {
        $this->login($this->makeAdmin());

        $existing = new Organization();
        $existing->setName('Первая');
        $this->em()->persist($existing);

        $previous = new Contact();
        $previous->setOrganization($existing)
            ->setName('Прежний основной')
            ->setIsMain(true);
        $this->em()->persist($previous);
        $this->em()->flush();

        $run = $this->createRunFromFixture(
            (new ImportCsvFixture())->row('Первая', '', '(10.05.2025) Звонок', 'Иванов, тел: +375171234567'),
        );

        $this->open('/admin/import/' . $run->id);
        // Первое утверждение помечает строку конфликтом: выбор слияния
        // отправляется той же формой, уже на перерисованном пакете.
        $this->approve($run, $this->approvalFieldsFromPage());
        self::assertResponseStatusCodeSame(422);

        $fields = $this->reviewRows();
        $fields[1]['contacts'][0]['isMain'] = '1';
        $this->approve($run, ['rows' => $fields, 'resolution' => [1 => 'merge']]);

        $this->assertResponseRedirects();
        $this->em()->clear();
        $organizations = $this->em()->getRepository(Organization::class)->findAll();
        self::assertCount(1, $organizations, 'слияние не создаёт вторую организацию');

        $contacts = $this->em()->getRepository(Contact::class)->findAll();
        self::assertCount(2, $contacts);

        $mains = array_values(array_filter($contacts, static fn(Contact $c): bool => $c->isMain));
        self::assertCount(1, $mains, 'основной контакт в организации один');
        self::assertSame('Иванов', $mains[0]->name);
    }

    /**
     * Заметка контакта — заполненное поле, поэтому контакт, у которого админ
     * оставил только заметку, сохраняется: под константное «Без имени».
     */
    public function testContactClearedToNotesOnlyIsCreated(): void
    {
        $this->login($this->makeAdmin());
        $run = $this->createRunFromFixture(
            (new ImportCsvFixture())->row('Первая', '', '(10.05.2025) Звонок', 'Иванов, тел: +375171234567'),
        );

        $this->open('/admin/import/' . $run->id);
        $fields = $this->approvalFieldsFromPage();
        $fields['rows'][1]['contacts'][0]['name'] = '';
        $fields['rows'][1]['contacts'][0]['phone'] = '';
        $fields['rows'][1]['contacts'][0]['notes'] = 'Записан по телефону';

        $this->approve($run, $fields);

        $this->assertResponseRedirects();
        $this->em()->clear();
        $contacts = $this->em()->getRepository(Contact::class)->findAll();
        self::assertCount(1, $contacts);
        self::assertSame('Без имени', $contacts[0]->name);
        self::assertSame('Записан по телефону', $contacts[0]->notes);
    }

    /**
     * В форме проверки у контакта есть заметки и отметка основного, у звонка —
     * дата, заметки и отметка планового.
     */
    public function testThePackageFormOffersNotesAndMainMarkForContacts(): void
    {
        $this->login($this->makeAdmin());
        $run = $this->createRunFromFixture(
            (new ImportCsvFixture())->row('Первая', '', '(10.05.2025) Звонок', 'Иванов, тел: +375171234567'),
        );

        $this->open('/admin/import/' . $run->id);
        $crawler = $this->client->getCrawler();

        self::assertCount(1, $crawler->filter('textarea[name="rows[1][contacts][0][notes]"]'));
        self::assertCount(1, $crawler->filter('input[type="checkbox"][name="rows[1][contacts][0][isMain]"]'));
        // Скрытое поле пары нужно, чтобы снятая отметка приходила как «0».
        self::assertCount(1, $crawler->filter('input[type="hidden"][name="rows[1][contacts][0][isMain]"]'));
        self::assertCount(1, $crawler->filter('textarea[name="rows[1][calls][0][notes]"]'));
    }

    /**
     * В форме проверки у контакта и звонка есть только поля, кнопки удаления
     * нет. Очистить поля — единственный способ отказаться от контакта или
     * звонка, поэтому строка обязана сохраниться, а пустая сущность не должна
     * создаваться и не должна давать ошибку валидации.
     */
    public function testClearedContactIsNotCreatedAndDoesNotStopTheImport(): void
    {
        $this->login($this->makeAdmin());
        $run = $this->createRunFromFixture(
            (new ImportCsvFixture())
                ->row('Первая', '', '(10.05.2025) Звонок', 'Иванов, тел: +375171234567')
                ->row('Вторая', '', '(11.05.2025) Звонок', 'Петров, тел: +375291234567'),
        );

        $this->open('/admin/import/' . $run->id);
        $fields = $this->approvalFieldsFromPage();
        $fields['rows'][1]['contacts'][0]['name'] = '';
        $fields['rows'][1]['contacts'][0]['phone'] = '';

        $this->approve($run, $fields);

        // Не 422: импорт не остановился.
        $this->assertResponseRedirects();
        $this->em()->clear();
        self::assertNotNull($this->findOrganization('Первая'));
        self::assertNotNull($this->findOrganization('Вторая'));

        $contacts = $this->em()->getRepository(Contact::class)->findAll();
        self::assertCount(1, $contacts, 'очищенный контакт не создаётся');
        self::assertSame('Петров', $contacts[0]->name);
        self::assertSame(2, $this->singleRun()->processedRows);
    }

    /**
     * Контакт со значениями, но без имени: имя очистил администратор, телефон
     * оставил намеренно. Выбрасывать его молча нельзя, а сохранить с пустым
     * именем нельзя — на нём стоит NotBlank. Создаётся под «Без имени».
     */
    public function testContactClearedToPhoneOnlyIsCreatedAsAnonymous(): void
    {
        $this->login($this->makeAdmin());
        $run = $this->createRunFromFixture(
            (new ImportCsvFixture())->row('Первая', '', '(10.05.2025) Звонок', 'Иванов, тел: +375171234567'),
        );

        $this->open('/admin/import/' . $run->id);
        $fields = $this->approvalFieldsFromPage();
        $fields['rows'][1]['contacts'][0]['name'] = '';

        $this->approve($run, $fields);

        $this->assertResponseRedirects();
        $this->em()->clear();
        $contacts = $this->em()->getRepository(Contact::class)->findAll();
        self::assertCount(1, $contacts);
        self::assertSame('Без имени', $contacts[0]->name);
        self::assertSame('+375 17 123-45-67', $contacts[0]->phone);
    }

    /**
     * Звонок без даты и без заметок не создаётся, заметка без даты — создаётся:
     * терять текст администратора незачем.
     */
    public function testClearedCallIsNotCreatedButCallWithNotesIs(): void
    {
        $this->login($this->makeAdmin());
        $run = $this->createRunFromFixture(
            (new ImportCsvFixture())->row('Первая', '', '(10.05.2025) Звонок, записали разговор'),
        );

        $this->open('/admin/import/' . $run->id);
        $fields = $this->approvalFieldsFromPage();
        $fields['rows'][1]['calls'][0]['date'] = '';
        $fields['rows'][1]['calls'][0]['notes'] = '';

        $this->approve($run, $fields);

        $this->assertResponseRedirects();
        $this->em()->clear();
        self::assertNotNull($this->findOrganization('Первая'));
        self::assertCount(0, $this->em()->getRepository(Call::class)->findAll());
    }

    public function testCallWithNotesAndNoDateIsCreated(): void
    {
        $this->login($this->makeAdmin());
        $run = $this->createRunFromFixture(
            (new ImportCsvFixture())->row('Первая', '', '(10.05.2025) Звонок, записали разговор'),
        );

        $this->open('/admin/import/' . $run->id);
        $fields = $this->approvalFieldsFromPage();
        $fields['rows'][1]['calls'][0]['date'] = '';

        $this->approve($run, $fields);

        $this->assertResponseRedirects();
        $this->em()->clear();
        $calls = $this->em()->getRepository(Call::class)->findAll();
        self::assertCount(1, $calls);
        self::assertNull($calls[0]->madeAt);
        self::assertNotNull($calls[0]->notes);
    }

    /**
     * Пустое название организации — другой случай: очистка вложенного контакта
     * его не отменяет, строка по-прежнему останавливает импорт.
     */
    public function testEmptyOrganizationNameStillStopsAfterContactIsCleared(): void
    {
        $this->login($this->makeAdmin());
        $run = $this->createRunFromFixture(
            (new ImportCsvFixture())
                ->row('Первая', '', '(10.05.2025) Звонок', 'Иванов, тел: +375171234567')
                ->row('', '', '(11.05.2025) Звонок', 'Петров, тел: +375291234567'),
        );

        $this->open('/admin/import/' . $run->id);
        $fields = $this->approvalFieldsFromPage();
        $fields['rows'][2]['name'] = '';
        $fields['rows'][2]['contacts'][0]['name'] = '';
        $fields['rows'][2]['contacts'][0]['phone'] = '';
        $fields['rows'][2]['calls'][0]['date'] = '';
        $fields['rows'][2]['calls'][0]['notes'] = '';

        $this->approve($run, $fields);

        $this->assertResponseStatusCodeSame(422);
        $this->assertPageContains('название организации не заполнено');
        $this->em()->clear();
        self::assertNotNull($this->findOrganization('Первая'));
        self::assertNull($this->findOrganization('Петров'));
    }

    public function testRecordWithoutAnyDataIsSkippedWithItsOwnNotice(): void
    {
        $this->login($this->makeAdmin());
        $run = $this->createRunFromFixture(
            (new ImportCsvFixture())
                ->row('Первая', '', '(10.05.2025) Звонок')
                ->row('', 'Курс А')
                ->row('Вторая', '', '(11.05.2025) Звонок'),
        );
        self::assertSame(3, $run->totalRows);

        $this->open('/admin/import/' . $run->id);

        // Пропуск сообщается на странице пакета, до нажатия «Импортировать».
        $this->assertPageContains('Строка 2 пропущена — не содержит требуемых данных организации');
        // Пропущенной строки в таблице нет: подтверждать и исправлять нечего.
        $this->assertSelectorNotExists('input[name="rows[2][name]"]');
        $this->assertSelectorExists('input[name="rows[1][name]"]');
        $this->assertSelectorExists('input[name="rows[3][name]"]');

        $this->approve($run, $this->approvalFieldsFromPage());

        $this->assertResponseRedirects();
        $this->em()->clear();
        // Счётчик продвинут и по пропущенной строке: иначе пакет всегда
        // начинался бы с неё и импорт не двигался бы.
        self::assertSame(3, $this->singleRun()->processedRows);
        // Организаций на две меньше, чем строк.
        $organizations = $this->em()->getRepository(\App\Entity\Organization::class)->findAll();
        self::assertCount(2, $organizations);
        self::assertNotNull($this->findOrganization('Первая'));
        self::assertNotNull($this->findOrganization('Вторая'));
    }

    /**
     * Строка без названия и контактов, но со звонком — как строки 336 и 351
     * выгрузки. Раньше она доходила до валидации и останавливала импорт
     * ошибкой «Название обязательно для заполнения». Назвать строку нельзя, а
     * звать её некуда, поэтому она пропускается.
     */
    public function testRowWithoutNameAndContactsIsSkippedEvenWithCalls(): void
    {
        $this->login($this->makeAdmin());
        $run = $this->createRunFromFixture(
            (new ImportCsvFixture())
                ->row('Первая', '', '(10.05.2025) Звонок')
                ->row('', '', '(11.05.2025) Звонок')
                ->row('Вторая', '', '(12.05.2025) Звонок'),
        );

        $this->open('/admin/import/' . $run->id);
        $this->assertPageContains('Строка 2 пропущена — не содержит требуемых данных организации');
        $this->assertPageNotContains('Название обязательно для заполнения');
        $this->assertSelectorNotExists('input[name="rows[2][name]"]');

        $this->approve($run, $this->approvalFieldsFromPage());

        $this->assertResponseRedirects();
        $this->em()->clear();
        self::assertSame(3, $this->singleRun()->processedRows);
        $organizations = $this->em()->getRepository(\App\Entity\Organization::class)->findAll();
        self::assertCount(2, $organizations);
    }

    public function testSeveralEmptyRecordsAreAllNamedInTheNotice(): void
    {
        $this->login($this->makeAdmin());
        $run = $this->createRunFromFixture(
            (new ImportCsvFixture())
                ->row('Первая', '', '(10.05.2025) Звонок')
                ->row('', 'Курс А')
                ->row('Вторая', '', '(11.05.2025) Звонок')
                ->row('', 'Курс Б')
                ->row('Третья', '', '(12.05.2025) Звонок')
                ->row('', 'Курс В'),
        );

        $this->open('/admin/import/' . $run->id);
        $this->assertPageContains('Строки 2, 4, 6 пропущены — не содержат требуемых данных организации');

        $this->approve($run, $this->approvalFieldsFromPage());

        $this->client->followRedirect();
        $this->em()->clear();
        self::assertSame(6, $this->singleRun()->processedRows);
    }

    public function testNoNoticeWhenNothingWasSkipped(): void
    {
        $this->login($this->makeAdmin());
        $run = $this->createRun(3);

        $this->open('/admin/import/' . $run->id);
        $this->approve($run, $this->approvalFieldsFromPage());

        $this->client->followRedirect();
        $this->assertPageNotContains('не содержат требуемых данных организации');
        $this->assertPageNotContains('не содержит требуемых данных организации');
    }

    public function testEmptyNameWithContactsIsNotSkippedButStopsTheImport(): void
    {
        $this->login($this->makeAdmin());
        $run = $this->createRunFromFixture(
            (new ImportCsvFixture())
                ->row('Первая', '', '(10.05.2025) Звонок')
                ->row('', '', '', 'Иван Петров, тел: +375171234567')
                ->row('Третья', '', '(12.05.2025) Звонок'),
        );

        $this->open('/admin/import/' . $run->id);
        $this->approve($run, $this->approvalFieldsFromPage());

        // Пустое название при контактах — это не пустая запись: импорт
        // останавливается, чтобы название исправили, а не идёт дальше.
        $this->assertResponseStatusCodeSame(422);
        $this->assertPageContains('Строка 2');
        $this->assertPageNotContains('Пропущено строк без данных');

        $this->em()->clear();
        self::assertSame(1, $this->singleRun()->processedRows);
    }

    public function testCorrectedNameSavesTheStoppedRowAndTheRestOfThePackage(): void
    {
        $this->login($this->makeAdmin());
        $run = $this->createRun(20);

        $this->open('/admin/import/' . $run->id);
        $fields = $this->approvalFieldsFromPage();
        $fields['rows'][13]['name'] = '';
        $this->approve($run, $fields);

        $this->em()->clear();
        self::assertSame(12, $this->singleRun()->processedRows);

        $this->open('/admin/import/' . $run->id);
        $fields = $this->approvalFieldsFromPage();
        $fields['rows'][13]['name'] = 'Исправленная организация';
        $this->approve($run, $fields);

        $this->em()->clear();
        self::assertSame(20, $this->singleRun()->processedRows);
        self::assertNotNull($this->findOrganization('Исправленная организация'));
    }

    public function testDuplicateNameStopsTheRowAndOffersMergeOrCreateNew(): void
    {
        $this->login($this->makeAdmin());
        $existing = (new Organization())->setName('Строка 1');
        $this->em()->persist($existing);
        $this->em()->flush();

        $run = $this->createRun(5);

        $this->open('/admin/import/' . $run->id);
        $this->approve($run, $this->approvalFieldsFromPage());

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('.alert--error', 'Строка 1');
        $this->assertSelectorExists('.import-row--conflict');
        $this->assertPageContains('Слить с существующей');
        $this->assertPageContains('Создать новую');
        self::assertSame(1, $this->firstRowNumber());

        $this->em()->clear();
        self::assertSame(0, $this->singleRun()->processedRows);
        self::assertCount(1, $this->em()->getRepository(Organization::class)->findAll());
    }

    public function testMergeChoiceCompletesTheChunkInOneSubmission(): void
    {
        $this->login($this->makeAdmin());
        $existing = (new Organization())->setName('Строка 1');
        $this->em()->persist($existing);
        $this->em()->flush();
        $run = $this->createRun(5);

        $this->open('/admin/import/' . $run->id);
        $this->approve($run, $this->approvalFieldsFromPage());
        // Выбор отправляется той же формой: конфликт помечен на перерисованном
        // пакете, и на свежей странице проверки его уже нет.
        $this->approve($run, ['rows' => $this->reviewRows(), 'resolution' => [1 => 'merge']]);

        $this->em()->clear();
        self::assertSame(5, $this->singleRun()->processedRows);
        // Слияние не создаёт вторую организацию, а дополняет существующую.
        $organizations = $this->em()->getRepository(Organization::class)->findAll();
        self::assertCount(5, $organizations);
        $merged = $this->findOrganization('Строка 1');
        self::assertNotNull($merged);
        // Слияние дополняет существующую карточку и не трогает её полей.
        self::assertGreaterThanOrEqual(1, \count($merged->contacts));
        self::assertNull($merged->createdBy);
        self::assertTrue($merged->isActive);
    }

    public function testCreateNewChoiceAllowsADuplicateName(): void
    {
        $this->login($this->makeAdmin());
        $existing = (new Organization())->setName('Строка 1');
        $this->em()->persist($existing);
        $this->em()->flush();
        $run = $this->createRun(5);

        $this->open('/admin/import/' . $run->id);
        $this->approve($run, $this->approvalFieldsFromPage());
        $this->approve($run, ['rows' => $this->reviewRows(), 'resolution' => [1 => 'create']]);

        $this->em()->clear();
        self::assertSame(5, $this->singleRun()->processedRows);
        self::assertCount(2, $this->em()->getRepository(Organization::class)->findBy(['name' => 'Строка 1']));
    }

    public function testDuplicateInsideTheSameFileIsCaughtAtInsertTime(): void
    {
        $this->login($this->makeAdmin());
        $run = $this->createRunFromFixture(
            (new ImportCsvFixture())
                ->row('Двойник', '', '(10.05.2025) Первый звонок')
                ->row('Двойник', '', '(11.05.2025) Второй звонок')
                ->row('Третья', '', '(12.05.2025) Третий звонок'),
        );

        $this->open('/admin/import/' . $run->id);
        $this->approve($run, $this->approvalFieldsFromPage());

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('.alert--error', 'Строка 2');
        self::assertSame(2, $this->firstRowNumber());

        $this->em()->clear();
        self::assertSame(1, $this->singleRun()->processedRows);
    }

    public function testReplacementReportLeavesTheRunUntouchedAndIsReproducible(): void
    {
        $this->login($this->makeAdmin());
        $run = $this->createRun(10, processed: 5);
        $candidate = $this->storeCandidate(
            (new ImportCsvFixture())
                ->row('Строка 1 изменена')
                ->row('Строка 2')
                ->row('Строка 3')
                ->row('Строка 4')
                ->row('Строка 5')
                ->row('Строка 6')
                ->row('Строка 7'),
        );

        $this->open('/admin/import/' . $run->id . '/replace?candidate=' . $candidate . '&name=' . urlencode('исправленный.csv'));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Замена файла');
        $this->assertPageContains('Строка 6');
        $this->assertPageContains('Строк в текущем файле');
        $this->assertSelectorExists('button[value="confirm"]');
        $this->assertSelectorExists('button[value="cancel"]');

        $this->em()->clear();
        $reloaded = $this->singleRun();
        self::assertSame(5, $reloaded->processedRows);
        self::assertSame(10, $reloaded->totalRows);
        self::assertFileExists($this->storage->path($reloaded->storageKey));

        // Отчёт воспроизводим по тому же адресу.
        $this->open('/admin/import/' . $run->id . '/replace?candidate=' . $candidate . '&name=' . urlencode('исправленный.csv'));
        $this->assertResponseIsSuccessful();
        $this->assertPageContains('Строка 6');
    }

    public function testConfirmedReplacementSwapsTheFileAndDeletesThePreviousOne(): void
    {
        $this->login($this->makeAdmin());
        $run = $this->createRun(10, processed: 5);
        $previousKey = $run->storageKey;
        $candidate = $this->storeCandidate(
            (new ImportCsvFixture())->row('А', '', '', '', '', '', '', '', '')->row('Б', '', '', '', '', '', '', '', ''),
        );
        $url = '/admin/import/' . $run->id . '/replace?candidate=' . $candidate . '&name=' . urlencode('исправленный.csv');

        $this->open('/admin/import/' . $run->id);
        $this->client->request('POST', $url, ['_csrf_token' => $this->csrfToken(), 'action' => 'confirm']);

        $this->em()->clear();
        $reloaded = $this->singleRun();
        self::assertSame($candidate, $reloaded->storageKey);
        self::assertSame('исправленный.csv', $reloaded->filename);
        self::assertSame(2, $reloaded->totalRows);
        self::assertSame(5, $reloaded->processedRows);
        self::assertFileDoesNotExist($this->storage->path($previousKey));
        self::assertFileExists($this->storage->path($candidate));

        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert--success', 'Импорт продолжен с строки 6');
    }

    public function testCancelledReplacementKeepsTheCurrentFileAndDeletesTheCandidate(): void
    {
        $this->login($this->makeAdmin());
        $run = $this->createRun(10, processed: 5);
        $previousKey = $run->storageKey;
        $candidate = $this->storeCandidate(
            (new ImportCsvFixture())->row('А', '', '', '', '', '', '', '', '')->row('Б', '', '', '', '', '', '', '', ''),
        );

        $this->open('/admin/import/' . $run->id);
        $this->client->request('POST', '/admin/import/' . $run->id . '/replace?candidate=' . $candidate
            . '&name=' . urlencode('исправленный.csv'), [
                '_csrf_token' => $this->csrfToken(),
                'action' => 'cancel',
            ]);

        $this->em()->clear();
        $reloaded = $this->singleRun();
        self::assertSame($previousKey, $reloaded->storageKey);
        self::assertSame(10, $reloaded->totalRows);
        self::assertSame(5, $reloaded->processedRows);
        self::assertFileExists($this->storage->path($previousKey));
        self::assertFileDoesNotExist($this->storage->path($candidate));
    }

    public function testShorterReplacementWarnsAndCompletesTheRun(): void
    {
        $this->login($this->makeAdmin());
        $run = $this->createRun(10, processed: 5);
        $candidate = $this->storeCandidate(
            (new ImportCsvFixture())->row('А', '', '', '', '', '', '', '', ''),
        );

        $this->open('/admin/import/' . $run->id);
        $this->client->request('POST', '/admin/import/' . $run->id . '/replace?candidate=' . $candidate
            . '&name=' . urlencode('короткий.csv'), [
                '_csrf_token' => $this->csrfToken(),
                'action' => 'confirm',
            ]);

        $this->assertResponseRedirects('/admin/import');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert--warning', 'считается завершённым');

        $this->em()->clear();
        $reloaded = $this->singleRun();
        self::assertSame(5, $reloaded->processedRows);
        self::assertSame(1, $reloaded->totalRows);
    }

    public function testInvalidReplacementNeverReachesTheReport(): void
    {
        $this->login($this->makeAdmin());
        $run = $this->createRun(10, processed: 5);
        $previousKey = $run->storageKey;
        $candidate = $this->storeRawFile(implode(',', [...CsvParser::HEADERS, '']) . "\n");

        $this->open('/admin/import/' . $run->id);
        $this->client->request('POST', '/admin/import/' . $run->id . '/replace?candidate=' . $candidate
            . '&name=' . urlencode('пустой.csv'), [
                '_csrf_token' => $this->csrfToken(),
                'action' => 'confirm',
            ]);

        $this->assertResponseRedirects('/admin/import/' . $run->id);
        $this->em()->clear();
        $reloaded = $this->singleRun();
        self::assertSame($previousKey, $reloaded->storageKey);
        self::assertSame(10, $reloaded->totalRows);
    }

    public function testImportListShowsActionStatesAndLastProcessedDate(): void
    {
        $this->login($this->makeAdmin());
        $notStarted = $this->createRun(5);
        $inProgress = $this->createRun(50, processed: 20);
        $finished = $this->createRun(40, processed: 40);
        $aboveTotal = $this->createRun(30, processed: 45);

        $this->open('/admin/import');

        $this->assertPageContains('Загружен');
        $this->assertPageContains('Импортирован');

        // «Импортировать» и «Перезагрузить» — только у незавершённых прогонов,
        // «Скачать» — у любого.
        foreach ([$notStarted, $inProgress] as $run) {
            $this->assertRowAction($run->filename, 'Импортировать');
            $this->assertRowAction($run->filename, 'Перезагрузить');
        }
        foreach ([$finished, $aboveTotal] as $run) {
            self::assertSame(['Скачать'], $this->rowActions($run->filename));
        }
        foreach ([$notStarted, $inProgress, $finished, $aboveTotal] as $run) {
            $this->assertRowAction($run->filename, 'Скачать');
        }

        // Дата последней обработанной строки пуста у прогона без сохранённых строк.
        self::assertSame('', $this->rowCell($notStarted->filename, self::LAST_PROCESSED_COLUMN));
        self::assertMatchesRegularExpression(
            '/\d{2}\.\d{2}\.\d{4}/',
            $this->rowCell($inProgress->filename, self::LAST_PROCESSED_COLUMN),
        );

        // Формат источника колонкой не показывается.
        $this->assertPageNotContains('Формат источника');
    }

    public function testReviewPageOffersNoFileReplacement(): void
    {
        $this->login($this->makeAdmin());
        $run = $this->createRun(3);

        $this->open('/admin/import/' . $run->id);

        $this->assertResponseIsSuccessful();
        // Замена запускается из списка, на странице пакета её нет.
        $this->assertPageNotContains('Заменить файл');
        self::assertSame(0, $this->client->getCrawler()->filter('form[action*="/replace"]')->count());
        self::assertSame(0, $this->client->getCrawler()->filter('input[type="file"]')->count());
    }

    public function testReviewPageSubmitsThePackageByTheImportButton(): void
    {
        $this->login($this->makeAdmin());
        $run = $this->createRun(3);

        $this->open('/admin/import/' . $run->id);

        $buttons = $this->client->getCrawler()
            ->filter('form button[type="submit"]')
            ->each(static fn($node): string => trim($node->text()));
        self::assertSame(['Импортировать'], $buttons);
    }

    /**
     * «Назад к списку» — навигация, а не утверждение пакета и не отмена
     * прогона: прогон остаётся незавершённым и продолжает блокировать новые
     * загрузки (design D9).
     */
    public function testBackToListLeavesTheRunUntouched(): void
    {
        $this->login($this->makeAdmin());
        $run = $this->createRun(3);

        $crawler = $this->open('/admin/import/' . $run->id);
        $back = $crawler->filterXPath('//a[normalize-space(.) = "Назад к списку"]');
        self::assertCount(1, $back);
        self::assertSame('/admin/import', $back->attr('href'));
        // Ссылка, а не кнопка отправки: переход не может утвердить пакет.
        // Что submit у формы ровно один и он «Импортировать» — проверяет
        // testReviewPageSubmitsThePackageByTheImportButton.
        self::assertSame('a', $back->getNode(0)->nodeName);

        $this->open($back->attr('href') ?? '/admin/import');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Импорт организаций');

        $this->em()->clear();
        self::assertSame(0, $this->singleRun()->processedRows);
        self::assertSame([], $this->em()->getRepository(Organization::class)->findAll());
    }

    public function testCallFromNextContactIsMarkedPlanned(): void
    {
        $this->login($this->makeAdmin());
        $run = $this->createRunFromFixture(
            (new ImportCsvFixture())
                ->row('С планом', '', '(10.05.2025) Созвон', '', '08.06.2026', 'созвониться по КП'),
        );

        $crawler = $this->open('/admin/import/' . $run->id);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.import-nested__planned', 'Планируемый');
        // Отметка стоит у планового звонка из «Следующего контакта», а у
        // записи взаимодействия — нет.
        $marks = $crawler->filterXPath('//label[contains(@class, "import-nested__planned")]');
        self::assertCount(2, $marks);
        $checked = $crawler->filterXPath('//label[contains(@class, "import-nested__planned")][.//input[@checked]]');
        self::assertCount(1, $checked);
        // Отметка стоит у звонка с датой из «Следующего контакта»: блок, в
        // котором она включена, содержит именно эту дату.
        $fieldset = $checked->closest('fieldset');
        self::assertSame('08.06.2026', $fieldset->filter('input[name$="[date]"]')->attr('value'));
        self::assertSame('созвониться по КП', trim($fieldset->filter('textarea')->text()));
    }

    public function testReviewTableHasNoCityOrAnnualPlanField(): void
    {
        $this->login($this->makeAdmin());
        $run = $this->createRun(3);

        $this->open('/admin/import/' . $run->id);

        $html = $this->client->getResponse()->getContent();
        self::assertIsString($html);
        self::assertStringNotContainsString('[city]', $html);
        self::assertStringNotContainsString('annualPlan', $html);
    }

    public function testHeaderMenuLinksToTheImportForAdminOnly(): void
    {
        $this->login($this->makeAdmin());
        $this->open('/organizations/new');

        $this->assertSelectorExists('[data-header-admin] a[href="/admin/import"]');

        $this->client->restart();
        $this->login($this->makeUser('manager-menu', 'manager-menu@b2b-crm.loc', UserRole::Manager));
        $this->open('/organizations/new');

        $this->assertSelectorNotExists('[data-header-admin]');
    }

    public function testImportedOrganizationIsVisibleOnTheDashboard(): void
    {
        $this->login($this->makeAdmin());
        $run = $this->uploadFixture('valid.csv');

        $this->open('/admin/import/' . $run->id);
        $this->approve($run, $this->approvalFieldsFromPage());

        $this->em()->clear();
        $this->open('/dashboard?q=' . urlencode('Нафтан'));

        $this->assertResponseIsSuccessful();
        $this->assertPageContains('Нафтан');
    }

    // --- helpers ---------------------------------------------------------

    private function pageContent(): string
    {
        $content = (string) $this->client->getResponse()->getContent();
        self::assertIsString($content);

        return $content;
    }

    /**
     * assertSelectorTextContains проверяет только ПЕРВУЮ подходящую ячейку,
     * поэтому содержимое страницы проверяется напрямую.
     */
    private function assertPageContains(string $needle): void
    {
        self::assertStringContainsString($needle, $this->pageContent());
    }

    private function assertPageNotContains(string $needle): void
    {
        self::assertStringNotContainsString($needle, $this->pageContent());
    }

    /**
     * Строка списка прогонов по имени файла. Symfony CSS-селектор не умеет
     * :has-text, поэтому строка ищется через XPath.
     *
     * @return \Symfony\Component\DomCrawler\Crawler
     */
    private function runRow(string $filename)
    {
        $rows = $this->client->getCrawler()->filterXPath(
            \sprintf('//table//tr[td[contains(., %s)]]', json_encode($filename, JSON_UNESCAPED_UNICODE)),
        );
        self::assertCount(1, $rows, \sprintf('Строка прогона «%s» не найдена.', $filename));

        return $rows->first();
    }

    /**
     * Подписи действий в колонке действий строки, по порядку. «Скачать» —
     * ссылка, «Перезагрузить» и подтверждения — кнопки, поэтому обходятся оба
     * тега.
     *
     * @return string[]
     */
    private function rowActions(string $filename): array
    {
        $cell = $this->runRow($filename)->filter('td')->last();

        $labels = [];
        foreach (['a', 'button'] as $tag) {
            foreach ($cell->filter($tag) as $node) {
                $labels[] = trim($node->textContent);
            }
        }

        return array_values(array_filter($labels, static fn(string $label): bool => '' !== $label));
    }

    private function assertRowAction(string $filename, string $expected): void
    {
        self::assertContains($expected, $this->rowActions($filename));
    }

    private function rowCell(string $filename, int $index): string
    {
        return trim($this->runRow($filename)->filter('td')->eq($index)->text());
    }

    /**
     * Загрузка фикстуры через форму страницы импорта — тем же путём, что и
     * администратор.
     */
    private function uploadFixture(string $fixtureName): ImportRun
    {
        $this->open('/admin/import');
        $this->upload($fixtureName);
        self::assertResponseRedirects('/admin/import');

        return $this->singleRun();
    }

    private function makeAdmin(): User
    {
        return $this->makeUser('admin-import', 'admin-import@b2b-crm.loc', UserRole::Admin);
    }

    private function makeUser(string $login, string $email, UserRole $role): User
    {
        $user = (new User())->setLogin($login)->setEmail($email)->setRole($role);
        $user->setPassword('test-password-hash');
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function upload(string $fixtureName): void
    {
        $contents = (string) file_get_contents($this->fixturePath($fixtureName));
        $this->client->request('POST', '/admin/import/upload', [
            '_csrf_token' => $this->csrfToken(),
        ], [
            'file' => $this->uploadedFile($contents, $fixtureName),
        ]);
    }

    private function csrfToken(): string
    {
        $token = $this->open('/admin/import')->filter('input[name="_csrf_token"]')->attr('value');
        self::assertIsString($token);

        return $token;
    }

    private function uploadedFile(string $contents, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'import-');
        self::assertIsString($path);
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }

    private function fixturePath(string $name): string
    {
        return \dirname(__DIR__, 2) . '/fixtures/import/' . $name;
    }

    private function createRun(int $rows, int $processed = 0): ImportRun
    {
        $fixture = new ImportCsvFixture();
        for ($i = 1; $i <= $rows; ++$i) {
            $fixture->row(
                'Строка ' . $i,
                '',
                '(0' . (1 + $i % 9) . '.0' . (1 + $i % 9) . '.202' . (4 + $i % 2) . ') Звонок ' . $i,
                'Иван Петров, тел: +3751712345' . (67 + $i % 10),
            );
        }

        return $this->createRunFromFixture($fixture, 'импорт-' . $rows . '-' . $processed . '.csv', $processed);
    }

    private function createRunFromFixture(ImportCsvFixture $fixture, string $filename = 'импорт.csv', int $processed = 0): ImportRun
    {
        $storageKey = $this->storage->storeContents($fixture->content());

        $run = (new ImportRun())
            ->setFilename($filename)
            ->setStorageKey($storageKey)
            ->setSourceFormat(ImportRun::SOURCE_FORMAT_CSV)
            ->setTotalRows($this->parser->countRecords($this->storage->path($storageKey)))
            ->setCreatedBy($this->currentAdmin());
        // markRowProcessed(), а не setProcessedRows(): прогон должен выглядеть
        // как прогон, действительно сохранивший строки, — иначе «дата
        // последней обработанной строки» в списке была бы пустой.
        for ($i = 0; $i < $processed; ++$i) {
            $run->markRowProcessed();
        }
        $this->em()->persist($run);
        $this->em()->flush();

        return $run;
    }

    private function currentAdmin(): User
    {
        $admin = $this->em()->getRepository(User::class)->findOneBy(['login' => 'admin-import']);
        if (!$admin instanceof User) {
            $admin = $this->makeAdmin();
        }

        return $admin;
    }

    private function storeCandidate(ImportCsvFixture $fixture): string
    {
        return $this->storage->storeContents($fixture->content());
    }

    private function storeRawFile(string $contents): string
    {
        return $this->storage->storeContents($contents);
    }

    /**
     * @return array{rows: array<int, array<string, string>>}
     */
    private function approvalFieldsFromPage(): array
    {
        return ['rows' => $this->reviewRows()];
    }

    /**
     * Отправка формы утверждения обычным POST: DomCrawler не умеет вложенные
     * имена вида `rows[3][contacts][0][name]`, а форма именно такая.
     *
     * @param array<string, mixed> $fields
     */
    private function approve(ImportRun $run, array $fields): void
    {
        $this->client->request('POST', '/admin/import/' . $run->id . '/approve', [
            '_csrf_token' => $this->csrfToken(),
            ...$fields,
        ]);
    }

    /**
     * Поля формы ровно такие, какие отрисовала страница проверки: тест
     * утверждает настоящий пакет, а не собранные вручную данные.
     *
     * @return array<int, array<string, mixed>>
     */
    private function reviewRows(): array
    {
        $rows = [];
        $crawler = $this->client->getCrawler();

        foreach ($crawler->filter('input[name$="[rowNumber]"]') as $node) {
            \assert($node instanceof \DOMElement);
            $number = (int) $node->getAttribute('value');
            $prefix = 'rows[' . $number . ']';
            $values = [];

            foreach ($crawler->filter(\sprintf('*[name^="%s["]', $prefix)) as $field) {
                \assert($field instanceof \DOMElement);
                $name = $field->getAttribute('name');
                $key = substr($name, \strlen($prefix) + 1);
                $type = $field->getAttribute('type');

                if ('checkbox' === $type) {
                    $value = $field->hasAttribute('checked') ? '1' : '0';
                } elseif ('hidden' === $type) {
                    $value = $field->getAttribute('value');
                } else {
                    $value = $field->textContent !== '' ? $field->textContent : $field->getAttribute('value');
                }

                $this->assign($values, $this->splitName($key), $value);
            }

            $rows[$number] = $values;
        }

        self::assertNotEmpty($rows);

        return $rows;
    }

    /**
     * `contacts[0][name]` → ['contacts', 0, 'name']: имя поля формы надо
     * разобрать в массив, иначе PHP не построит вложенность из
     * `contacts][0][name`.
     *
     * @return array<int, string|int>
     */
    private function splitName(string $key): array
    {
        preg_match_all('/^([A-Za-z_]+)|\[([^\]]+)\]/', $key, $matches, PREG_SET_ORDER);
        $parts = [];
        foreach ($matches as $match) {
            $part = '' !== ($match[1] ?? '') ? $match[1] : ($match[2] ?? '');
            $parts[] = ctype_digit($part) ? (int) $part : $part;
        }

        return $parts;
    }

    /**
     * @param array<string, mixed>          $target
     * @param array<int, string|int>        $path
     */
    private function assign(array &$target, array $path, string $value): void
    {
        $cursor = &$target;
        $last = \count($path) - 1;
        foreach ($path as $index => $part) {
            if ($index === $last) {
                $cursor[$part] = $value;
                break;
            }
            if (!isset($cursor[$part]) || !\is_array($cursor[$part])) {
                $cursor[$part] = [];
            }
            $cursor = &$cursor[$part];
        }
    }

    /**
     * @return int[]
     */
    private function rowNumbers(): array
    {
        return $this->client->getCrawler()
            ->filter('.import-row__number')
            ->each(static fn($node): int => (int) trim($node->text()));
    }

    private function firstRowNumber(): int
    {
        $numbers = $this->rowNumbers();
        self::assertNotEmpty($numbers);

        return $numbers[0];
    }

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

    /**
     * Импорт создаёт организации, контакты и звонки: они удаляются первыми,
     * иначе каскад не даст удалить организации.
     */
    private function purgeImportData(): void
    {
        $em = $this->em();
        foreach ($em->getRepository(Contact::class)->findAll() as $contact) {
            $em->remove($contact);
        }
        foreach ($em->getRepository(Call::class)->findAll() as $call) {
            $em->remove($call);
        }
        foreach ($em->getRepository(OrgGroupMembership::class)->findAll() as $membership) {
            $em->remove($membership);
        }
        foreach ($em->getRepository(Organization::class)->findAll() as $organization) {
            $em->remove($organization);
        }
        foreach ($this->runs() as $run) {
            $this->storage->delete($run->storageKey);
            $em->remove($run);
        }
        $em->flush();
    }
}
