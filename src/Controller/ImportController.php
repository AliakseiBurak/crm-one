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
use App\Service\Import\ImportFileStorage;
use App\Service\Import\ImportProcessor;
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
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Список прогонов и форма загрузки.
     */
    #[Route('', name: 'app_import_index', methods: ['GET'])]
    public function list(): Response
    {
        return $this->render('organization_import/index.html.twig', [
            'runs' => $this->runs->findAllNewestFirst(),
        ]);
    }

    /**
     * Загрузка файла. Пока есть прогон с `processedRows < totalRows`, новая
     * загрузка отклоняется: активный импорт ровно один (design D9).
     */
    #[Route('/upload', name: 'app_import_upload', methods: ['POST'])]
    public function upload(Request $request): Response
    {
        $this->assertCsrfToken($request);

        $active = $this->runs->findUnfinished();
        if (null !== $active) {
            $this->addFlash('error', \sprintf(
                'Импорт «%s» не завершён: %d из %d строк. Продолжите его или замените его файл, '
                . 'прежде чем загружать новый.',
                $active->filename,
                $active->processedRows,
                $active->totalRows,
            ));

            return $this->redirectToRoute('app_import_index');
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $this->addFlash('error', 'Файл не выбран.');

            return $this->redirectToRoute('app_import_index');
        }

        $storageKey = $this->storage->store($file);
        $path = $this->storage->path($storageKey);

        try {
            $this->parser->assertUsable($path);
        } catch (CsvFormatException $e) {
            $this->storage->delete($storageKey);
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_import_index');
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

        return $this->redirectToRoute('app_import_index');
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

        $unfinished = $this->runs->findUnfinished();
        if (null !== $unfinished && $unfinished->id !== $run->id) {
            // Страница только показывает состояние, поэтому отказ заменён
            // перенаправлением на незавершённый импорт.
            return $this->redirectToRoute('app_import_review', ['id' => $unfinished->id]);
        }

        if ($run->isFinished()) {
            $this->addFlash('success', $this->completionNotice($run));

            return $this->redirectToRoute('app_import_index');
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

            return $this->redirectToRoute('app_import_index');
        }

        $chunk = $this->processor->processChunk($run);

        try {
            $result = $this->processor->persistRows(
                $run,
                // Пропущенных строк в форме нет, но счётчик обязан перешагнуть
                // каждую строку файла — иначе импорт встанет на них навсегда.
                $chunk->forPersist($this->rowsFromRequest($request)),
                $this->resolutionsFromRequest($request),
            );
        } catch (ImportRowConflict|ImportRowStopped $e) {
            $this->addFlash('error', $e->getMessage());

            $rows = $this->sliceFrom($this->rowsFromRequest($request), $e->rowNumber);
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

            return $this->redirectToRoute('app_import_index');
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
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $this->addFlash('error', 'Файл не выбран.');

            return $this->redirectToRoute('app_import_review', ['id' => $run->id]);
        }

        $storageKey = $this->storage->store($file);
        try {
            // Замена проверяется по формату, объявленному для источника самого
            // прогона; не прошедший файл до отчёта не доходит.
            $this->parser->assertUsable($this->storage->path($storageKey));
        } catch (CsvFormatException $e) {
            $this->storage->delete($storageKey);
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_import_review', ['id' => $run->id]);
        }

        return $this->redirectToRoute('app_import_replace', [
            'id' => $run->id,
            'candidate' => $storageKey,
            'name' => $file->getClientOriginalName(),
        ]);
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

        $candidatePath = $this->storage->path($candidateKey);
        try {
            $this->parser->assertUsable($candidatePath);
        } catch (CsvFormatException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_import_review', ['id' => $run->id]);
        }

        $report = $this->replacementReport($run, $candidateKey);
        $previousKey = $run->storageKey;
        $filename = (string) $request->query->get('name', '');

        $run
            ->setFilename('' === $filename ? 'файл прогона' : $filename)
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

            return $this->redirectToRoute('app_import_index');
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
        $current = [];
        foreach ($this->parser->records($this->storage->path($run->storageKey)) as $index => $record) {
            $current[$index] = CsvParser::recordText($record);
        }

        $candidate = [];
        $resumeOrganization = '';
        foreach ($this->parser->records($this->storage->path($candidateKey)) as $index => $record) {
            $candidate[$index] = CsvParser::recordText($record);
            if ($index === $run->processedRows) {
                $resumeOrganization = CsvParser::organizationName($record);
            }
        }

        $changed = [];
        for ($row = 0; $row < $run->processedRows; ++$row) {
            if (($current[$row] ?? null) !== ($candidate[$row] ?? null)) {
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
     * @return ImportRow[]
     */
    private function rowsFromRequest(Request $request): array
    {
        $rows = [];
        foreach ($request->request->all('rows') as $data) {
            if (isset($data['rowNumber'])) {
                $rows[] = $this->rowFromRequest($data);
            }
        }

        return $rows;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function rowFromRequest(array $data): ImportRow
    {
        $row = new ImportRow(
            rowNumber: (int) $data['rowNumber'],
            name: (string) ($data['name'] ?? ''),
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
     * «Импортировано в этом прогоне: X, импортировано всего: Y» (design D11).
     */
    private function completionNotice(ImportRun $run): string
    {
        // X считает решённые строки: сохранённые и пропущенные как пустые
        // (design D2), поэтому «импортировано» было бы неправдой, а
        // пропуски названы отдельно в момент, когда они произошли.
        return \sprintf(
            'Обработано строк в этом прогоне: %d, обработано всего: %d',
            $run->processedRows,
            $this->runs->sumProcessedRows(),
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
        $value = null === $value ? null : trim((string) $value);

        return '' === $value ? null : $value;
    }

    private function assertCsrfToken(Request $request): void
    {
        $token = $request->headers->get('X-CSRF-Token') ?? (string) $request->request->get('_csrf_token', '');
        if (!$this->isCsrfTokenValid('import', $token)) {
            throw new AccessDeniedHttpException('Недействительный CSRF-токен');
        }
    }
}
