<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Задание архивирования журнала отправки писем по годам (change
 * email-send-logging). Запускается Symfony Scheduler ежедневно и, при
 * развёртывании по crontab, консольной командой.
 */
final class ArchiveMailerLogs {}
