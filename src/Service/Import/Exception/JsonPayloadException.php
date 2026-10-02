<?php

declare(strict_types=1);

namespace App\Service\Import\Exception;

/**
 * Ответ не является корректным JSON либо не проходит объявленный контракт.
 *
 * Отдельное исключение, а не `CsvFormatException`: файл ответа приходит не из
 * CSV-выгрузки, и текст сообщения должен говорить про JSON, иначе администратор
 * на вкладке «JSON» получил бы сообщение про колонки CSV.
 */
final class JsonPayloadException extends \RuntimeException {}
