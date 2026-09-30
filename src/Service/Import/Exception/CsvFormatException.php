<?php

declare(strict_types=1);

namespace App\Service\Import\Exception;

/**
 * Файл не проходит проверку объявленного формата источника.
 *
 * Нарушение обнаруживается до того, как что-либо записано: прогон и его текущий
 * файл остаются нетронутыми, а сообщение перечисляет, чего именно не хватает.
 */
class CsvFormatException extends \RuntimeException {}
