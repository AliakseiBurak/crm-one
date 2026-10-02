<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\ImportRow;
use App\Dto\ImportRowCall;
use App\Dto\ImportRowContact;
use App\Entity\ImportRun;
use App\Entity\User;
use App\Repository\ImportRunRepository;
use App\Service\Import\CsvParser;
use App\Service\Import\Exception\CsvFormatException;
use App\Service\Import\Exception\ImportRowConflict;
use App\Service\Import\Exception\ImportRowStopped;
use App\Service\Import\Exception\JsonPayloadException;
use App\Service\Import\ImportFileStorage;
use App\Service\Import\ImportJsonSchema;
use App\Service\Import\ImportProcessor;
use App\Service\Import\ImportRunReader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Импорт организаций из CSV (change add-organizations-csv-import).
 * Доступно только администратору.
 *
 * Прогон импорта — запись в БД, а не серверная сессия (design D2): между
 * запросами на сервере не передаётся ничего, прогон и файл-кандидат приходят
 * в URL. Поэтому повторное открытие одного адреса показывает тот же пакет, а
 * форма утверждения идемпотентна — она всегда начинает с `processedRows + 1` и
 * пропускает всё, что не выше счётчика.
 */
#[Route('/admin/import', requirements: ['id' => '\d+'])]
#[IsGranted('ROLE_ADMIN')]
class ImportController extends AbstractController
{
    public function __construct(
        private readonly ImportRunRepository $runs,
        private readonly ImportFileStorage $storage,
        private readonly CsvParser $parser,
        private readonly ImportProcessor $processor,
        private readonly ImportJsonSchema $jsonSchema,
        private readonly ImportRunReader $reader,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Первая вкладка импорта: список прогонов.
     *
     * Таблица получила свою вкладку, потому что на остальных она была чужой:
     * вкладка отвечает за то, как строка получена, а прогон — это уже состояние
     * импорта, и на странице загрузки ему не место. Заодно вкладки перестали
     * различаться по списку прогонов — раньше он был одинаковым везде, и это
     * только мешало.
     */
    #[Route('/results', name: 'app_import_results', requirements: ['id' => '0'], methods: ['GET'])]
    public function results(): Response
    {
        return $this->render('organization_import/results.html.twig', [
            'runs' => $this->runs->findAllNewestFirst(),
        ]);
    }

    /**
     * Вкладка загрузки CSV.
     *
     * Списка прогонов здесь нет: он на вкладке «Результаты», а сюда приходят
     * за формой. После загрузки администратор возвращается на список — так он
     * сразу видит созданный прогон.
     */
    #[Route('', name: 'app_import_index', methods: ['GET'])]
    public function list(): Response
    {
        return $this->render('organization_import/index.html.twig');
    }

    /**
     * Загрузка файла CSV.
     *
     * Незавершённые прогоны не мешают: у каждого свой файл и свой счётчик, а
     * таблица даёт действия в каждой строке, поэтому несколько импортов живут
     * рядом (design D12).
     */
    #[Route('/upload', name: 'app_import_upload', methods: ['POST'])]
    public function upload(Request $request): Response
    {
        $this->assertCsrfToken($request);

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $this->addFlash('error', 'Файл не выбран.');

            return $this->redirectToRoute('app_import_results');
        }

        $storageKey = $this->storage->store($file);
        $path = $this->storage->path($storageKey);

        try {
            $this->parser->assertUsable($path);
        } catch (CsvFormatException $e) {
            $this->storage->delete($storageKey);
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_import_results');
        }

        $run = (new ImportRun())
            ->setFilename($file->getClientOriginalName())
            ->setStorageKey($storageKey)
            ->setSourceFormat(ImportRun::SOURCE_FORMAT_CSV)
            ->setTotalRows($this->parser->countRecords($path))
            ->setCreatedBy($this->currentUser());
        $this->em->persist($run);
        $this->em->flush();

        // Администратор возвращается в список, где файл виден отдельной
        // строкой. Разбор записей и проверка пакета по 20 строк на загрузке не
        // начинаются: их запускает действие «Импортировать» в этой строке.
        $this->addFlash('success', \sprintf(
            'Файл «%s» загружен: %d строк. Импорт начнётся по кнопке «Импортировать».',
            $run->filename,
            $run->totalRows,
        ));

        return $this->redirectToRoute('app_import_results');
    }

    /**
     * Вкладка «JSON»: загрузка ответа файлом.
     *
     * Метод называется не `json()`, потому что это имя уже занято
     * `AbstractController::json()` — помощником для JSON-ответов.
     *
     * Вставки ответа здесь нет: ответ приходит к администратору из вкладки «LLM»
     * файлом, он его скачивает и загружает сюда. Смешивать вставку и файл было
     * незачем — два способа доставить одно и то же расходятся только на
     * странице загрузки, а на странице модели достаточно одного.
     *
     * Ответ — уже разобранные объекты, а не ячейки CSV, поэтому проверяется он
     * по опубликованной схеме, а не по заголовкам колонок (design D2).
     */
    #[Route('/json', name: 'app_import_json', requirements: ['id' => '0'], methods: ['GET', 'POST'])]
    public function jsonTab(Request $request): Response
    {
        if ('POST' === $request->getMethod()) {
            $this->assertCsrfToken($request);

            return $this->submitJsonResponse($request);
        }

        return $this->render('organization_import/json.html.twig', [
            'violations' => [],
        ]);
    }

    /**
     * Отправка ответа файлом.
     *
     * Разбор в DTO и пакет для проверки здесь не начинаются — как и на вкладке
     * CSV, импорт запускает «Импортировать» в строке списка (design D3). Проверка
     * ответа при этом происходит сразу: она дешёвая, и негодный ответ
     * отклоняется до того, как станет прогоном.
     */
    private function submitJsonResponse(Request $request): Response
    {
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $this->addFlash('error', 'Файл не выбран.');

            return $this->redirectToRoute('app_import_json');
        }

        $text = @file_get_contents($file->getPathname());
        if (false === $text) {
            $this->addFlash('error', 'Файл не читается.');

            return $this->redirectToRoute('app_import_json');
        }

        $filename = $file->getClientOriginalName();

        try {
            $payload = $this->jsonSchema->decode($text);
            $result = $this->jsonSchema->validate($payload);
        } catch (JsonPayloadException $e) {
            return $this->renderJsonRejection($e->getMessage());
        }

        if (!$result->isValid()) {
            return $this->renderJsonRejection($result->report());
        }

        $storageKey = $this->storage->storeContents($text);
        $run = (new ImportRun())
            ->setFilename($filename)
            ->setStorageKey($storageKey)
            ->setSourceFormat(ImportRun::SOURCE_FORMAT_JSON)
            ->setTotalRows($this->jsonSchema->organizationCount($payload))
            ->setCreatedBy($this->currentUser());
        $this->em->persist($run);
        $this->em->flush();

        $this->addFlash('success', \sprintf(
            'Ответ «%s» сохранён: %d организаций. Импорт начнётся по кнопке «Импортировать».',
            $run->filename,
            $run->totalRows,
        ));

        return $this->redirectToRoute('app_import_results');
    }

    /**
     * Отклонённый ответ показывается на той же вкладке, с отчётом: прогон не
     * создан, файл не сохранён, а текст остаётся в поле, чтобы администратор
     * правил его, а не искал ответ заново.
     */
    private function renderJsonRejection(string $report): Response
    {
        $response = $this->render('organization_import/json.html.twig', [
            'violations' => explode("\n", $report),
        ]);

        $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);

        return $response;
    }

    /**
     * Текст замены: вставка и файл неразличимы дальше этого места.
     *
     * Нужно только замене файла уже созданного прогона: основная вкладка JSON
     * ответа не принимает вставку, там файл один. Пустое имя на месте вставки
     * означает «не переименовывать прогон».
     *
     * @return array{0: string, 1: string}
     */
    private function submittedPayload(Request $request): array
    {
        $pasted = (string) $request->request->get('payload', '');
        if ('' !== trim($pasted)) {
            return [$pasted, ''];
        }

        $file = $request->files->get('file');
        if ($file instanceof UploadedFile && $file->isValid()) {
            $contents = @file_get_contents($file->getPathname());

            return [false === $contents ? '' : $contents, $file->getClientOriginalName()];
        }

        return ['', ''];
    }

    /**
     * Скачивание опубликованного контракта: тот же документ, по которому
     * проверяется ответ и из которого собран промпт (design D2).
     */
    #[Route('/json-schema', name: 'app_import_json_schema', requirements: ['id' => '0'], methods: ['GET'])]
    public function jsonSchema(): Response
    {
        $response = new BinaryFileResponse($this->jsonSchema->path());
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            'organization-import.schema.json',
        );
        $response->headers->set('Content-Type', 'application/schema+json; charset=UTF-8');

        return $response;
    }

    /**
     * Вкладка «Промпт»: та же инструкция, что отправляет вкладка LLM.
     *
     * Отдельная страница, а не блок на странице модели, потому что инструкцией
     * пользуются и без модели: её копируют в любой чат, где ответ нужен от
     * другой системы. На вкладке LLM она только упоминается ссылкой — там
     * содержанием страницы она не является.
     */
    #[Route('/prompt', name: 'app_import_prompt', requirements: ['id' => '0'], methods: ['GET'])]
    public function promptTab(): Response
    {
        return $this->render('organization_import/prompt.html.twig', [
            'prompt' => $this->jsonSchema->prompt(),
        ]);
    }

    /**
     * Вкладка LLM-компонента.
     *
     * Страница целиком клиентская: запрос уходит из браузера прямо провайдеру, и
     * сервер не получает ни ключа, ни промпта, ни ответа (design D4). Поэтому
     * здесь нет ни одного серверного вызова — только разметка и клиент из
     * `assets/js`.
     */
    #[Route('/llm', name: 'app_import_llm', requirements: ['id' => '0'], methods: ['GET'])]
    public function llm(): Response
    {
        return $this->render('organization_import/llm.html.twig', [
            'prompt' => $this->jsonSchema->prompt(),
            // Клиент отправляет провайдеру проекцию того же документа, а не его
            // копию: проекция выведена из схемы, которая проверяет ответ, поэтому
            // разойтись с ней не может. Провайдеру отдаётся костяк формы —
            // генератор грамматики Ollama не переваривает `$ref` и `$id` и падает
            // с «failed to parse grammar» (design D2), — а проверку длины,
            // форматов дат и лишних полей сервер всё равно делает у себя.
            'import_schema' => $this->jsonSchema->providerSchema(),
        ]);
    }

    /**
     * Файл текущего прогона отдаётся под именем, которым он был загружен.
     * Доступно для любого прогона, включая завершённый: файл остаётся
     * справочным материалом после импорта.
     */
    #[Route('/{id}/download', name: 'app_import_download', methods: ['GET'])]
    public function download(int $id): Response
    {
        $run = $this->run($id);
        $path = $this->storage->path($run->storageKey);
        if (!is_file($path)) {
            throw $this->createNotFoundException('Файл прогона не найден.');
        }

        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            '' === $run->filename ? 'import.csv' : $run->filename,
        );
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');

        return $response;
    }

    /**
     * Пакет для проверки. Позиция пакета берётся из прогресса прогона, а не из
     * запроса: адрес `/admin/import/{id}` — закладка, которая всегда
     * показывает один и тот же пакет, а позиция, присланная клиентом, его не
     * сдвигает (design D2).
     */
    #[Route('/{id}', name: 'app_import_review', methods: ['GET'])]
    public function review(int $id): Response
    {
        $run = $this->run($id);

        if ($run->isFinished()) {
            $this->addFlash('success', $this->completionNotice($run));

            return $this->redirectToRoute('app_import_results');
        }

        $chunk = $this->processor->processChunk($run);
        $this->addSkippedNotice($chunk->skippedRowNumbers);

        return $this->render('organization_import/review.html.twig', [
            'run' => $run,
            'rows' => $chunk->visibleRows(),
            'stoppedRow' => null,
        ]);
    }

    /**
     * Утверждение пакета. Остановка на строке — конфликт по названию, отказ базы
     * или пустое название — перерисовывает пакет **с этой строки** с введёнными
     * значениями; после выбора слияния остаток пакета сохраняется в том же
     * запросе (design D7, D10).
     */
    #[Route('/{id}/approve', name: 'app_import_approve', methods: ['POST'])]
    public function approve(Request $request, int $id): Response
    {
        $this->assertCsrfToken($request);

        $run = $this->run($id);
        if ($run->isFinished()) {
            $this->addFlash('success', $this->completionNotice($run));

            return $this->redirectToRoute('app_import_results');
        }

        $chunk = $this->processor->processChunk($run);

        try {
            $result = $this->processor->persistRows(
                $run,
                // Пропущенных строк в форме нет, но счётчик обязан перешагнуть
                // каждую строку файла — иначе импорт встанет на них навсегда.
                $chunk->forPersist($this->rowsFromRequest($request, $this->isJsonRun($run))),
                $this->resolutionsFromRequest($request),
            );
        } catch (ImportRowConflict|ImportRowStopped $e) {
            $this->addFlash('error', $e->getMessage());

            $rows = $this->sliceFrom($this->rowsFromRequest($request, $this->isJsonRun($run)), $e->rowNumber);
            if ($e instanceof ImportRowConflict) {
                // Конфликт обнаружен на вставке, поэтому в отправленных полях его
                // нет: помечаем строку заново, чтобы на ней появился выбор.
                foreach ($rows as $row) {
                    if ($row->rowNumber === $e->rowNumber) {
                        $row->markConflict($e->existingOrganizationId, $e->existingOrganizationName);
                    }
                }
            }

            return $this->render('organization_import/review.html.twig', [
                'run' => $run,
                // Пакет показывается с остановившейся строки: значения,
                // введённые для следующих строк, сохраняются и остаются
                // редактируемыми.
                'rows' => $rows,
                'stoppedRow' => $e->rowNumber,
            ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        // О пропущенных строках уже сообщено на странице пакета, повторять то
        // же сообщение после нажатия «Импортировать» незачем. Сюда попадают
        // только те, которых в пакете не было — их могли опустошить в форме.
        $this->addSkippedNotice(array_values(array_diff($result->skipped, $chunk->skippedRowNumbers)));

        if ($run->isFinished()) {
            $this->addFlash('success', $this->completionNotice($run));

            return $this->redirectToRoute('app_import_results');
        }

        return $this->redirectToRoute('app_import_review', ['id' => $run->id]);
    }

    /**
     * Строки без данных организации и контактов пропускаются, и об этом
     * сообщается отдельным уведомлением с номерами строк: ничего не сломалось,
     * импорт идёт дальше, но менеджер должен знать, что в файле были строки,
     * которые не попали в базу.
     *
     * @param int[] $skipped
     */
    private function addSkippedNotice(array $skipped): void
    {
        if ([] === $skipped) {
            return;
        }

        $numbers = implode(', ', $skipped);
        $many = 1 < \count($skipped);

        $this->addFlash('warning', \sprintf(
            $many
                ? 'Строки %s пропущены — не содержат требуемых данных организации: нет ни названия, ни контактов.'
                : 'Строка %s пропущена — не содержит требуемых данных организации: нет ни названия, ни контактов.',
            $numbers,
        ));
    }

    /**
     * Замена файла прогона (design D8): отчёт, подтверждение и отмена.
     *
     * POST с файлом сохраняет кандидата и перенаправляет на эту же страницу с
     * `?candidate=<storageKey>&name=<file>` — отчёт становится закладкой,
     * воспроизводимой по адресу, без серверной сессии. POST с `action`
     * подтверждает замену или отменяет её. Прогон до подтверждения не меняется.
     */
    #[Route('/{id}/replace', name: 'app_import_replace', methods: ['GET', 'POST'])]
    public function replace(Request $request, int $id): Response
    {
        if ('POST' === $request->getMethod()) {
            $this->assertCsrfToken($request);
        }

        $run = $this->run($id);
        $this->assertReplaceable($run);

        if ('POST' !== $request->getMethod()) {
            return $this->renderReplacementReport($request, $run);
        }

        $action = (string) $request->request->get('action', '');
        if ('' !== $action) {
            return $this->finishReplacement($request, $run, $action);
        }

        return $this->storeReplacementCandidate($request, $run);
    }

    private function storeReplacementCandidate(Request $request, ImportRun $run): Response
    {
        [$text, $filename] = $this->submittedPayload($request);
        if ('' === trim($text)) {
            $this->addFlash('error', $this->isJsonRun($run)
                ? 'Файл не выбран и ответ не вставлен.'
                : 'Файл не выбран.');

            return $this->redirectToRoute('app_import_review', ['id' => $run->id]);
        }

        $storageKey = $this->storage->storeContents($text);
        try {
            // Замена проверяется по формату, объявленному для источника самого
            // прогона; не прошедший кандидат до отчёта не доходит, а на диске не
            // остаётся.
            $this->assertUsableFor($run, $storageKey);
        } catch (CsvFormatException|JsonPayloadException $e) {
            $this->storage->delete($storageKey);
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_import_review', ['id' => $run->id]);
        }

        // Кандидат едет в адресе: отчёт воспроизводим перезагрузкой этой ссылки,
        // и между двумя запросами на сервере не хранится ничего (design D8).
        return $this->redirectToRoute('app_import_replace', [
            'id' => $run->id,
            'candidate' => $storageKey,
            'name' => $filename,
        ]);
    }

    /**
     * Сохранённый кандидат годен для замены, если он годится в формате,
     * объявленном для источника самого прогона (design D6).
     */
    private function assertUsableFor(ImportRun $run, string $candidateKey): void
    {
        if ($this->isJsonRun($run)) {
            $result = $this->jsonSchema->validateText($this->storage->read($candidateKey));
            if (!$result->isValid()) {
                throw new JsonPayloadException($result->report());
            }

            return;
        }

        $this->parser->assertUsable($this->storage->path($candidateKey));
    }

    private function isJsonRun(ImportRun $run): bool
    {
        return ImportRun::SOURCE_FORMAT_JSON === $run->sourceFormat;
    }

    private function renderReplacementReport(Request $request, ImportRun $run): Response
    {
        $candidateKey = (string) $request->query->get('candidate', '');

        // Без файла-кандидата страница показывает выбор нового файла: отчёт
        // появляется только после того, как кандидат проверен и сохранён.
        if ('' === $candidateKey) {
            return $this->render('organization_import/replace.html.twig', [
                'run' => $run,
                'candidate' => null,
                'report' => null,
            ]);
        }

        if (!is_file($this->storage->path($candidateKey))) {
            $this->addFlash('error', 'Файл-кандидат не найден.');

            return $this->redirectToRoute('app_import_replace', ['id' => $run->id]);
        }

        return $this->render('organization_import/replace.html.twig', [
            'run' => $run,
            'candidate' => (string) $request->query->get('name', ''),
            'report' => $this->replacementReport($run, $candidateKey),
        ]);
    }

    /**
     * Подтверждение замены: прогон переключается на новый файл, прежний файл
     * удаляется, `totalRows` обновляется, `processedRows` сохраняется. Отмена
     * оставляет всё как было, а файл-кандидат удаляет как невостребованный.
     */
    private function finishReplacement(Request $request, ImportRun $run, string $action): Response
    {
        $candidateKey = (string) $request->query->get('candidate', '');
        if ('' === $candidateKey || !is_file($this->storage->path($candidateKey))) {
            $this->addFlash('error', 'Файл-кандидат не указан.');

            return $this->redirectToRoute('app_import_review', ['id' => $run->id]);
        }

        if ('cancel' === $action) {
            $this->storage->delete($candidateKey);
            $this->addFlash('success', \sprintf(
                'Замена отменена. Прогон «%s» продолжает работать с прежним файлом.',
                $run->filename,
            ));

            return $this->redirectToRoute('app_import_review', ['id' => $run->id]);
        }

        try {
            $this->assertUsableFor($run, $candidateKey);
        } catch (CsvFormatException|JsonPayloadException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_import_review', ['id' => $run->id]);
        }

        $report = $this->replacementReport($run, $candidateKey);
        $previousKey = $run->storageKey;
        $filename = (string) $request->query->get('name', '');

        $run
            // Имя из вставки не меняет прогон: замена правит файл и счётчики, а
            // как прогон называется в списке — то же самое. Своего имени у
            // вставки нет, и подставлять вместо него выдуманное значило бы
            // терять то, под чем прогон уже виден администратору.
            ->setFilename('' === $filename ? $run->filename : $filename)
            ->setStorageKey($candidateKey)
            ->setTotalRows($report['candidateRows']);
        $this->em->flush();

        // Прежний файл удаляется только здесь — единственное место, где поток
        // импорта удаляет файл (design D5, D8).
        $this->storage->delete($previousKey);

        if ($report['shorter']) {
            $this->addFlash('warning', \sprintf(
                'В новом файле %d строк, а обработано было %d: импорт считается завершённым.',
                $report['candidateRows'],
                $run->processedRows,
            ));
        }

        $this->addFlash('success', \sprintf(
            'Файл прогона заменён. Импорт продолжен с строки %d: «%s»',
            $report['resumeRow'],
            $report['resumeOrganization'],
        ));

        if ($run->isFinished()) {
            $this->addFlash('success', $this->completionNotice($run));

            return $this->redirectToRoute('app_import_results');
        }

        return $this->redirectToRoute('app_import_review', ['id' => $run->id]);
    }

    /**
     * Отчёт о замене. Доверяются только номера строк и текущий файл прогона
     * (design D8): `Organization.createdAt`/`updatedAt` не используются, и
     * никаких сведений о давности источника отчёт не содержит — файл приходит
     * из браузера и метки времени источника не несёт.
     *
     * @return array{currentRows: int, candidateRows: int, resumeRow: int,
     *     resumeOrganization: string, changedRows: int[], shorter: bool}
     */
    private function replacementReport(ImportRun $run, string $candidateKey): array
    {
        $format = $run->sourceFormat;
        $current = [];
        foreach ($this->reader->records($format, $this->storage->path($run->storageKey)) as $index => $record) {
            $current[$index] = $record;
        }

        $candidate = [];
        $resumeOrganization = '';
        foreach ($this->reader->records($format, $this->storage->path($candidateKey)) as $index => $record) {
            $candidate[$index] = $record;
            if ($index === $run->processedRows) {
                $resumeOrganization = $this->reader->organizationName($format, $record);
            }
        }

        // Сравнение построено на правилах формата, а не на тексте payload: CSV
        // сравнивается как запись, JSON — по полям (design D6).
        $changed = [];
        for ($row = 0; $row < $run->processedRows; ++$row) {
            if (!$this->reader->equals($format, $current[$row] ?? null, $candidate[$row] ?? null)) {
                $changed[] = $row + 1;
            }
        }

        return [
            'currentRows' => \count($current),
            'candidateRows' => \count($candidate),
            'resumeRow' => $run->processedRows + 1,
            'resumeOrganization' => $resumeOrganization,
            'changedRows' => $changed,
            'shorter' => \count($candidate) < $run->processedRows,
        ];
    }

    /**
     * Замена доступна, пока прогон не завершён.
     */
    private function assertReplaceable(ImportRun $run): void
    {
        if ($run->isFinished()) {
            throw $this->createNotFoundException('Прогон завершён: замена файла недоступна.');
        }
    }

    /**
     * Поля, которых нет в выгрузке, читаются из формы только у прогона из
     * JSON: у прогонов из CSV их в пакете нет, и подставленное клиентом значение
     * не должно попасть в организацию, которой эти поля не полагались
     * (design D7).
     *
     * @return ImportRow[]
     */
    private function rowsFromRequest(Request $request, bool $json): array
    {
        $rows = [];
        foreach ($request->request->all('rows') as $data) {
            if (isset($data['rowNumber'])) {
                $rows[] = $this->rowFromRequest($data, $json);
            }
        }

        return $rows;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function rowFromRequest(array $data, bool $json): ImportRow
    {
        $row = new ImportRow(
            rowNumber: (int) $data['rowNumber'],
            name: (string) ($data['name'] ?? ''),
            industry: $json ? $this->text($data['industry'] ?? null) : null,
            city: $json ? $this->text($data['city'] ?? null) : null,
            unp: $json ? $this->text($data['unp'] ?? null) : null,
            annualPlan: $json ? $this->text($data['annualPlan'] ?? null) : null,
            description: $this->text($data['description'] ?? null),
            coursesAttended: $this->text($data['coursesAttended'] ?? null),
            website: $this->text($data['website'] ?? null),
        );

        $conflictId = $data['conflictOrganizationId'] ?? null;
        if (null !== $conflictId && '' !== (string) $conflictId) {
            $row->conflictOrganizationId = (int) $conflictId;
            $row->conflictOrganizationName = $this->text($data['conflictOrganizationName'] ?? null);
        }

        foreach ((array) ($data['contacts'] ?? []) as $contact) {
            if (\is_array($contact)) {
                $row->contacts[] = new ImportRowContact(
                    name: (string) ($contact['name'] ?? ''),
                    phone: $this->text($contact['phone'] ?? null),
                    email: $this->text($contact['email'] ?? null),
                    position: $this->text($contact['position'] ?? null),
                    notes: $this->text($contact['notes'] ?? null),
                    // Чекбокс приходит двумя полями: скрытым «0» и самим «1».
                    // Значимо только то, что прислал браузер, — иначе очищенная
                    // отметка всегда читалась бы как включённая.
                    isMain: $this->checkbox($contact, 'isMain'),
                );
            }
        }

        foreach ((array) ($data['calls'] ?? []) as $call) {
            if (\is_array($call)) {
                $row->calls[] = new ImportRowCall(
                    date: (string) ($call['date'] ?? ''),
                    notes: $this->text($call['notes'] ?? null),
                    planned: (bool) ($call['planned'] ?? false),
                );
            }
        }

        return $row;
    }

    /**
     * Значение чекбокса из отправленных полей формы.
     *
     * Флажок отправляется парой: скрытое поле с «0» и сам флажок с «1», когда он
     * отмечен. Поэтому «1» означает включённый флажок, а отсутствие значения —
     * снятый, а не «включён по умолчанию».
     *
     * @param array<array-key, mixed> $data
     */
    private function checkbox(array $data, string $key): bool
    {
        return '1' === (string) ($data[$key] ?? '0');
    }

    /**
     * Выбор на остановившейся строке: слияние или создание нового. Отказ от
     * обоих вариантов — не тупик: строка переименовывается и утверждается
     * заново (design D10).
     *
     * @return array<int, string>
     */
    private function resolutionsFromRequest(Request $request): array
    {
        $resolutions = [];
        foreach ($request->request->all('resolution') as $rowNumber => $resolution) {
            if (\in_array((string) $resolution, ['merge', 'create'], true)) {
                $resolutions[(int) $rowNumber] = (string) $resolution;
            }
        }

        return $resolutions;
    }

    /**
     * @param ImportRow[] $rows
     *
     * @return ImportRow[]
     */
    private function sliceFrom(array $rows, int $rowNumber): array
    {
        $sliced = array_values(array_filter(
            $rows,
            static fn(ImportRow $row): bool => $row->rowNumber >= $rowNumber,
        ));
        usort($sliced, static fn(ImportRow $a, ImportRow $b): int => $a->rowNumber <=> $b->rowNumber);

        return $sliced;
    }

    /**
     * «Обработано строк в этом прогоне: X из Y» (design D11).
     *
     * Число — по текущему прогону, а не сумма по всем. Пока импорт был один,
     * сумма совпадала с числом строк в файле и читалась как «этот импорт»; с
     * несколькими независимыми прогонами сумма больше, чем строк в этом файле,
     * и отвечает на вопрос, которого не задавали.
     *
     * Формулировка считает строки, а не организации: X — это решённые строки,
     * сохранённые и пропущенные как пустые (design D2), поэтому «импортировано»
     * выдавало бы каждую пропущенную строку за организацию. Пропуски названы
     * отдельно в момент, когда они произошли.
     */
    private function completionNotice(ImportRun $run): string
    {
        return \sprintf(
            'Обработано строк в этом прогоне: %d из %d',
            $run->processedRows,
            $run->totalRows,
        );
    }

    private function run(int $id): ImportRun
    {
        $run = $this->runs->find($id);
        if (!$run instanceof ImportRun) {
            throw $this->createNotFoundException('Прогон импорта не найден.');
        }

        return $run;
    }

    private function currentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }

    private function text(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));

        return $trimmed !== '' ? $trimmed : null;
    }

    private function assertCsrfToken(Request $request): void
    {
        $token = $request->headers->get('X-CSRF-Token') ?? (string) $request->request->get('_csrf_token', '');
        if (!$this->isCsrfTokenValid('import', $token)) {
            throw new AccessDeniedHttpException('Недействительный CSRF-токен');
        }
    }
}
