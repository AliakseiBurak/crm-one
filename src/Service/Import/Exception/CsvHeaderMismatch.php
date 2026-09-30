<?php

declare(strict_types=1);

namespace App\Service\Import\Exception;

/**
 * Заголовки файла не совпадают с объявленным форматом: каких-то ожидаемых
 * колонок нет либо присутствуют неожиданные непустые колонки.
 *
 * Несёт и ожидаемые, и фактические заголовки — сообщение должно называть их
 * оба, чтобы администратор увидел, что именно исправлять.
 */
final class CsvHeaderMismatch extends CsvFormatException
{
    /**
     * @param string[] $expectedHeaders объявленные колонки формата
     * @param string[] $actualHeaders   непустые колонки файла, как есть
     */
    public function __construct(
        public readonly array $expectedHeaders,
        public readonly array $actualHeaders,
    ) {
        $missing = array_values(array_diff($expectedHeaders, $actualHeaders));
        $unexpected = array_values(array_diff($actualHeaders, $expectedHeaders));

        $details = [];
        if ([] !== $missing) {
            $details[] = 'отсутствуют: ' . implode(', ', array_map(
                static fn(string $header): string => '«' . $header . '»',
                $missing,
            ));
        }
        if ([] !== $unexpected) {
            $details[] = 'лишние: ' . implode(', ', array_map(
                static fn(string $header): string => '«' . $header . '»',
                $unexpected,
            ));
        }

        parent::__construct(\sprintf(
            'Структура файла не совпадает с ожидаемой (%s). Ожидаемые заголовки: %s.',
            implode('; ', $details),
            implode(', ', array_map(
                static fn(string $header): string => '«' . $header . '»',
                $expectedHeaders,
            )),
        ));
    }
}
