<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\MailerLogFile;
use App\Entity\User;
use App\Service\MailerLogFileLister;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Перечень файлов журнала отправки писем (change email-send-logging,
 * ADR-0016, D8–D9). Доступно только администратору.
 *
 * Страница перечисляет имена файлов и отдаёт выбранный файл; содержимое
 * журнала она не читает и не отображает. Единственное изменение, которое
 * страница вносит в файлы журнала, — удаление по явному действию
 * администратора (D17).
 */
#[Route('/admin/mailer-logs')]
#[IsGranted('ROLE_ADMIN')]
class MailerLogController extends AbstractController
{
    public function __construct(
        private readonly MailerLogFileLister $lister,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('', name: 'app_mailer_log_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('mailer_logs/index.html.twig', [
            'files' => $this->lister->files(),
            'currentMonth' => date('Y-m'),
        ]);
    }

    /**
     * Удаляется ровно один файл — тот, период которого указан в запросе.
     * Файл текущего календарного месяца не удаляется: обработчик Monolog держит
     * его открытым и писал бы в уже удалённый файл до ротации, то есть записи
     * пропали бы незаметно (D17). Защита двухслойная: у этой позиции нет
     * элемента удаления, а оба маршрута отклоняют такой период с 404.
     */
    #[Route(
        '/month/{month}/delete',
        name: 'app_mailer_log_month_delete',
        methods: ['GET'],
        requirements: ['month' => '\d{4}-\d{2}'],
    )]
    public function confirmMonthDelete(string $month): Response
    {
        if ($this->isCurrentMonth($month)) {
            throw new NotFoundHttpException('Файл текущего месяца удалить нельзя');
        }

        $path = $this->lister->resolveMonth($month);
        if (null === $path || !is_file($path)) {
            throw new NotFoundHttpException('Файл журнала отправки не найден');
        }

        return $this->render('mailer_logs/delete.html.twig', [
            'filename' => basename($path),
            'keepNotice' => \sprintf('Остальные файлы журнала отправки, включая файлы %s года, сохраняются.', substr($month, 0, 4)),
            'action' => $this->generateUrl('app_mailer_log_month_remove', ['month' => $month]),
        ]);
    }

    #[Route(
        '/month/{month}/delete',
        name: 'app_mailer_log_month_remove',
        methods: ['POST'],
        requirements: ['month' => '\d{4}-\d{2}'],
    )]
    public function removeMonth(string $month, Request $request): Response
    {
        // Отказ по текущему месяцу — до проверки CSRF и до любых действий с
        // диском: такой позиции в перечне удаляемых нет, поэтому ответ один и
        // тот же — 404 (D18).
        if ($this->isCurrentMonth($month)) {
            throw new NotFoundHttpException('Файл текущего месяца удалить нельзя');
        }

        $this->assertCsrfToken($request);
        $this->remove($this->lister->resolveMonth($month), $month, MailerLogFile::KIND_MONTH);

        return $this->redirectToList(\sprintf('Файл журнала %s удалён.', $month));
    }

    #[Route(
        '/year/{year}/delete',
        name: 'app_mailer_log_year_delete',
        methods: ['GET'],
        requirements: ['year' => '\d{4}'],
    )]
    public function confirmYearDelete(string $year): Response
    {
        $path = $this->lister->resolveYear($year);
        if (null === $path || !is_file($path)) {
            throw new NotFoundHttpException('Файл журнала отправки не найден');
        }

        return $this->render('mailer_logs/delete.html.twig', [
            'filename' => basename($path),
            'keepNotice' => \sprintf('Файлы месяцев %s года (mailer-%s-*.log) останутся на месте и сохранятся в перечне.', $year, $year),
            'action' => $this->generateUrl('app_mailer_log_year_remove', ['year' => $year]),
        ]);
    }

    #[Route(
        '/year/{year}/delete',
        name: 'app_mailer_log_year_remove',
        methods: ['POST'],
        requirements: ['year' => '\d{4}'],
    )]
    public function removeYear(string $year, Request $request): Response
    {
        $this->assertCsrfToken($request);
        $this->remove($this->lister->resolveYear($year), $year, MailerLogFile::KIND_YEAR);

        return $this->redirectToList(\sprintf('Архив журнала %s года удалён.', $year));
    }

    #[Route(
        '/month/{month}/download',
        name: 'app_mailer_log_month_download',
        methods: ['GET'],
        requirements: ['month' => '\d{4}-\d{2}'],
    )]
    public function downloadMonth(string $month): Response
    {
        return $this->download($this->lister->resolveMonth($month));
    }

    #[Route(
        '/year/{year}/download',
        name: 'app_mailer_log_year_download',
        methods: ['GET'],
        requirements: ['year' => '\d{4}'],
    )]
    public function downloadYear(string $year): Response
    {
        return $this->download($this->lister->resolveYear($year));
    }

    /**
     * Имя файла выводится из **проверенного** периода, а не берётся из
     * запроса, поэтому отдать что-либо, кроме файла журнала выбранного вида,
     * невозможно по построению.
     */
    private function download(?string $path): Response
    {
        if (null === $path || !is_file($path)) {
            throw new NotFoundHttpException('Файл журнала отправки не найден');
        }

        $response = new BinaryFileResponse($path);
        // Строки журнала содержат произвольный текст — наименования рассылок и
        // организаций, ответы SMTP-сервера, — который контролирует пользователь
        // или внешняя система. Без явного типа такой файл рискует быть
        // отдан браузеру как разметка, поэтому тип задаётся явно (D9).
        $response->headers->set('Content-Type', 'text/plain');
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                basename($path),
            ),
        );
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    /**
     * Период проверяется регулярным выражением **до** составления имени,
     * существование файла проверяется до `unlink()`, а имя выводится из
     * проверенного периода, поэтому удалить что-либо, кроме файла журнала
     * выбранного вида, невозможно по построению (D18).
     */
    private function remove(?string $path, string $period, string $kind): void
    {
        if (null === $path || !is_file($path)) {
            throw new NotFoundHttpException('Файл журнала отправки не найден');
        }

        unlink($path);

        $user = $this->getUser();
        // Запись об удалении принадлежит общему журналу `app`: служебная запись
        // в mailer-*.log нарушила бы требование «в журнале только результаты
        // фактической отправки» (D11, D18).
        $this->logger->warning('Файл журнала отправки удалён: {kind} {period} ({filename}) администратором {login}', [
            'kind' => $kind,
            'period' => $period,
            'filename' => basename($path),
            'user_id' => $user instanceof User ? $user->id : null,
            'login' => $user?->getUserIdentifier() ?? 'неизвестно',
        ]);
    }

    private function isCurrentMonth(string $period): bool
    {
        // Признак «текущий месяц» определяется сравнением периодов, а не
        // наличием открытого дескриптора: он детерминирован и одинаков для
        // веб-воркера и консоли.
        return $period === date('Y-m');
    }

    private function assertCsrfToken(Request $request): void
    {
        $token = $request->headers->get('X-CSRF-Token') ?? (string) $request->request->get('_csrf_token', '');
        if (!$this->isCsrfTokenValid('mailer_log_delete', $token)) {
            throw new AccessDeniedHttpException('Недействительный CSRF-токен');
        }
    }

    private function redirectToList(string $message): Response
    {
        $this->addFlash('success', $message);

        return $this->redirectToRoute('app_mailer_log_index');
    }
}
