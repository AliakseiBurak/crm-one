<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Campaign;
use App\Entity\CampaignRecipient;
use App\Entity\Contact;
use App\Entity\Organization;
use App\Repository\CampaignRecipientRepository;
use App\Repository\CampaignRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

readonly class MailingService
{
    public function __construct(
        private EntityManagerInterface $em,
        private MailerInterface $mailer,
        private CampaignRepository $campaignRepository,
        private CampaignRecipientRepository $recipientRepository,
        private UserRepository $userRepository,
        private CampaignAttachmentStorage $attachmentStorage,
        private UrlGeneratorInterface $urlGenerator,
        private LoggerInterface $logger,
        #[Autowire(param: 'mailing.from_email')]
        private string $fromEmail,
        #[Autowire(param: 'mailing.from_name')]
        private string $fromName,
    ) {}

    /**
     * Обработать одного получателя: одно письмо на организацию (TO + CC),
     * отправить, обновить статус.
     */
    public function processRecipient(CampaignRecipient $recipient): void
    {
        if (!$this->em->contains($recipient)) {
            $recipient = $this->recipientRepository->find($recipient->id);
            if (null === $recipient) {
                return;
            }
        }

        $campaign = $recipient->campaign;

        $recipient->markSending();
        $this->em->flush();

        if ($recipient->organization->isOptedOut) {
            $recipient->markFailed('Организация отписана от рассылок', false);
            $this->em->flush();
            $this->checkCampaignEscalation($campaign);

            return;
        }

        $resolved = $this->resolveEmailTargets($recipient);

        if (null === $resolved) {
            $recipient->markFailed('Отсутствует email-адрес организации/контакта', false);
            $this->em->flush();
            $this->checkCampaignEscalation($campaign);

            return;
        }

        [$contact, $toEmail, $ccEmails] = $resolved;

        try {
            $this->sendEmail($campaign, $recipient, $contact, $toEmail, $ccEmails);

            $this->logger->info('Campaign ID {id} send for {email}', [
                'id' => $campaign->id,
                'email' => $toEmail,
            ]);

            $recipient->markDelivered();
            $this->em->flush();
        } catch (\Throwable $e) {
            $message = $e->getMessage();

            if ($this->isBounceError($e)) {
                $this->logger->warning('Campaign ID {id} bounced for {email}: {message}', [
                    'id' => $campaign->id,
                    'email' => $toEmail,
                    'message' => $message,
                ]);

                $recipient->markBounced((string) $this->smtpStatusCode($e));
                $this->em->flush();
                $this->checkCampaignEscalation($campaign);

                return;
            }

            $isTransient = $this->isTransientError($e);

            $this->logger->warning('Campaign ID {id} send failed for {email}: {message}', [
                'id' => $campaign->id,
                'email' => $toEmail,
                'message' => $message,
            ]);

            $recipient->markFailed($message, $isTransient);
            $this->em->flush();
            $this->checkCampaignEscalation($campaign);
        }
    }

    /**
     * Одно письмо на организацию: контакт с email — TO этому контакту, CC —
     * остальные контакты организации с email; контакт без email или без
     * указания контакта — TO эффективному главному контакту (isMain; при его
     * отсутствии или нескольких — минимальный ID), CC — остальные контакты с
     * email. Имя получателя/токены — выбранный контакт (если указан) или
     * организация. Нет контактов с email — письмо не отправляется (null).
     *
     * @return array{0: Contact|null, 1: string, 2: list<string>}|null
     */
    private function resolveEmailTargets(CampaignRecipient $recipient): ?array
    {
        $specified = $recipient->contact;
        $organization = $recipient->organization;

        $orgEmails = $this->organizationEmails($organization);

        if (null !== $specified && null !== $specified->email && '' !== $specified->email) {
            return [$specified, $specified->email, $this->ccEmails($orgEmails, $specified->email)];
        }

        $main = $this->effectiveMainContact($organization);
        $mainEmail = null !== $main && null !== $main->email && '' !== $main->email ? $main->email : null;
        if (null === $mainEmail) {
            // Главный контакт без email: письмо уходит на первый email
            // организации (существующее поведение), если адреса есть.
            if ([] === $orgEmails) {
                return null;
            }

            $mainEmail = $orgEmails[0];
        }

        return [$specified, $mainEmail, $this->ccEmails($orgEmails, $mainEmail)];
    }

    /**
     * Эффективный главный контакт: isMain = true; при отсутствии такого
     * контакта или при нескольких — контакт с минимальным ID.
     */
    private function effectiveMainContact(Organization $organization): ?Contact
    {
        $mains = [];
        foreach ($organization->contacts as $contact) {
            if ($contact->isMain) {
                $mains[] = $contact;
            }
        }

        $candidates = [] !== $mains ? $mains : $organization->contacts->toArray();
        $main = null;
        foreach ($candidates as $contact) {
            if (null === $main || ($contact->id ?? PHP_INT_MAX) < ($main->id ?? PHP_INT_MAX)) {
                $main = $contact;
            }
        }

        return $main;
    }

    /**
     * @param list<string> $orgEmails
     *
     * @return list<string>
     */
    private function ccEmails(array $orgEmails, string $toEmail): array
    {
        return array_values(array_filter(
            $orgEmails,
            static fn(string $email): bool => strtolower($email) !== strtolower($toEmail),
        ));
    }

    /**
     * @return list<string>
     */
    private function organizationEmails(Organization $organization): array
    {
        $emails = [];
        foreach ($organization->contacts as $contact) {
            if (null !== $contact->email && '' !== $contact->email) {
                $key = strtolower($contact->email);
                if (!isset($emails[$key])) {
                    $emails[$key] = $contact->email;
                }
            }
        }

        return array_values($emails);
    }

    /**
     * @param list<string> $ccEmails
     */
    private function sendEmail(
        Campaign $campaign,
        CampaignRecipient $recipient,
        ?Contact $contact,
        string $toEmail,
        array $ccEmails,
    ): void {
        $organization = $recipient->organization;
        $unsubscribeUrl = $this->generateUnsubscribeUrl($recipient);
        $preview = $campaign->renderPreviewText($contact, $organization);
        $body = $this->preheaderMarkup($preview)
            . $campaign->renderBody($contact, $organization, $unsubscribeUrl)
            . $this->trackingPixelMarkup($recipient);

        // Имя получателя: выбранный контакт (даже без email), иначе —
        // название организации.
        $displayName = null !== $contact ? $contact->name : $organization->name;

        $email = new Email()
            ->from(new Address($this->fromEmail, $this->fromName))
            ->to(new Address($toEmail, $displayName))
            ->subject($campaign->renderSubject($contact, $organization))
            ->html($body);

        foreach ($ccEmails as $ccEmail) {
            $email->addCc($ccEmail);
        }

        foreach ($campaign->attachments as $attachment) {
            $path = $this->attachmentStorage->path($attachment->storageKey);
            if (!is_file($path)) {
                $this->logger->warning('Вложение рассылки #{id} не найдено: {key}', [
                    'id' => $attachment->id,
                    'key' => $attachment->storageKey,
                ]);
                continue;
            }

            $email->attachFromPath($path, $attachment->filename, $attachment->mimeType);
        }

        $this->mailer->send($email);
    }

    private function generateUnsubscribeUrl(CampaignRecipient $recipient): string
    {
        return $this->urlGenerator->generate(
            'app_unsubscribe',
            ['trackingToken' => $recipient->trackingToken],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }

    private function preheaderMarkup(?string $preview): string
    {
        if (null === $preview || '' === $preview) {
            return '';
        }

        return \sprintf(
            '<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;">%s</div>',
            htmlspecialchars($preview, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        );
    }

    private function trackingPixelMarkup(CampaignRecipient $recipient): string
    {
        if (null === $recipient->trackingToken) {
            return '';
        }

        $pixelUrl = $this->urlGenerator->generate(
            'app_tracking_pixel',
            ['trackingToken' => $recipient->trackingToken],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        return \sprintf(
            '<img src="%s" width="1" height="1" alt="" style="display:block;border:0;height:1px;width:1px">',
            htmlspecialchars($pixelUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        );
    }

    private function isBounceError(\Throwable $e): bool
    {
        $code = $this->smtpStatusCode($e);

        return null !== $code && $code >= 500 && $code < 600;
    }

    private function isTransientError(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        if (str_contains($message, 'timeout') || str_contains($message, 'timed out')) {
            return true;
        }

        $code = $this->smtpStatusCode($e);

        return null !== $code && $code >= 400 && $code < 500;
    }

    /**
     * SMTP-код из исключения: getCode(), иначе 4xx/5xx из текста ответа
     * (Symfony часто оставляет getCode() = 0 и пишет «got code "550"»).
     */
    private function smtpStatusCode(\Throwable $e): ?int
    {
        $code = $e->getCode();
        if ($code >= 400 && $code < 600) {
            return $code;
        }

        $message = $e->getMessage();
        if (preg_match('/got code ["\']?([45]\d{2})["\']?/i', $message, $matches)) {
            return (int) $matches[1];
        }
        if (preg_match('/\b(5\d{2})\b/', $message, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    private function checkCampaignEscalation(Campaign $campaign): void
    {
        $campaignId = $campaign->id;
        if (null === $campaignId) {
            return;
        }

        if ($this->recipientRepository->countStillProcessing($campaignId) > 0) {
            return;
        }

        $deliverableCount = $this->recipientRepository->countDeliveredOrOpened($campaignId);
        $total = $this->recipientRepository->countByCampaign($campaignId);

        if (0 === $deliverableCount && $total > 0) {
            $managed = $this->campaignRepository->find($campaignId);
            if (null === $managed) {
                return;
            }

            $reason = $this->failureReason($campaignId, $total);
            $managed->fail($reason);
            $this->em->flush();
            $this->notifyAdmin($managed, $reason);
        }
    }

    private function failureReason(int $campaignId, int $total): string
    {
        if ($this->recipientRepository->countNoEmailFailures($campaignId) === $total) {
            return 'У всех получателей отсутствует email-адрес. Добавьте email контактам или уберите адресатов без адреса, затем нажмите «Сбросить» и «Запустить».';
        }

        return 'Ни одно письмо не доставлено (ошибка SMTP или недоступные адреса). Проверьте MAILER_DSN и адреса получателей, затем нажмите «Сбросить» и «Запустить».';
    }

    private function notifyAdmin(Campaign $campaign, string $reason): void
    {
        $admins = $this->userRepository->findAdmins();

        if (empty($admins)) {
            $this->logger->warning('Нет администраторов для уведомления об ошибке рассылки #{id}', [
                'id' => $campaign->id,
            ]);

            return;
        }

        $subject = \sprintf('Ошибка рассылки #%d: %s', $campaign->id, $campaign->name);
        $html = \sprintf(
            '<h2>Рассылка #%d перешла в статус «Ошибка»</h2>'
            . '<p><strong>Название:</strong> %s</p>'
            . '<p><strong>Причина:</strong> %s</p>'
            . '<p>Исправьте проблему, нажмите «Сбросить», затем «Запустить».</p>',
            $campaign->id,
            htmlspecialchars($campaign->name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($reason, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        );

        foreach ($admins as $admin) {
            $email = new Email()
                ->from(new Address($this->fromEmail, $this->fromName))
                ->to($admin->email)
                ->subject($subject)
                ->html($html);

            try {
                $this->mailer->send($email);
            } catch (\Throwable $e) {
                $this->logger->error('Не удалось отправить уведомление администратору {email}: {message}', [
                    'email' => $admin->email,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }
}
