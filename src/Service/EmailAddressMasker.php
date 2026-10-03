<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Маскирование адресов в журнале отправки писем (change email-send-logging,
 * ADR-0016, design D3). Локальная часть сокращается до первого символа,
 * домен сохраняется: `client@example.ru` → `c***@example.ru`.
 *
 * Две операции, которые не сводятся одна к другой: `mask()`/`maskList()`
 * работают с адресами, `maskInText()` — с произвольным текстом, потому что
 * ответ MTA подставляет получателя в текст ошибки, и без очистки полный адрес
 * лёг бы в файл рядом со своей маской.
 */
final readonly class EmailAddressMasker
{
    /**
     * Адрес в произвольном тексте. Левая граница не даёт захватить адрес,
     * который является частью другого (например, `a@b` внутри `xa@b`).
     */
    private const string ADDRESS_IN_TEXT = '/(?<![A-Za-z0-9._%+\-])[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)+/';

    /**
     * Значение без «@», целиком составленное из символов адреса, — это
     * некорректный адрес, а не свободный текст: его локальная часть
     * утекла бы в журнал, поэтому оно маскируется целиком.
     */
    private const string ADDRESS_LIKE = '/^[A-Za-z0-9._%+\-]+$/';

    /**
     * Знаки, которые могут стоять сразу за адресом в тексте ответа MTA и не
     * принадлежат самому адресу (`<client@example.ru>:`, `example.ru.`).
     */
    private const string TRAILING_PUNCTUATION = '.,;:!?)<';

    public function mask(string $email): string
    {
        $at = strrpos($email, '@');
        if (false === $at) {
            return $this->looksLikeAddress($email) ? '***' : $email;
        }

        $local = substr($email, 0, $at);
        $domain = substr($email, $at);

        // Пустая или отсутствующая локальная часть утечки не даёт: маскируется
        // всё, что до «@».
        return ('' === $local ? '***' : $local[0] . '***') . $domain;
    }

    /**
     * @param list<string> $emails
     *
     * @return list<string>
     */
    public function maskList(array $emails): array
    {
        return array_map($this->mask(...), $emails);
    }

    /**
     * Заменяет маской **все** адреса строки, а не только первый: ответ MTA
     * многострочный, и получатель встречается в нём в угловых скобках и в
     * разных местах.
     */
    public function maskInText(string $text): string
    {
        return preg_replace_callback(
            self::ADDRESS_IN_TEXT,
            fn(array $matches): string => $this->maskWithTrailingPunctuation($matches[0]),
            $text,
        ) ?? $text;
    }

    private function maskWithTrailingPunctuation(string $matched): string
    {
        $trailing = '';
        while ('' !== $matched && str_contains(self::TRAILING_PUNCTUATION, $matched[-1])) {
            $trailing = $matched[-1] . $trailing;
            $matched = substr($matched, 0, -1);
        }

        return $this->mask($matched) . $trailing;
    }

    private function looksLikeAddress(string $value): bool
    {
        return '' !== $value && 1 === preg_match(self::ADDRESS_LIKE, $value);
    }
}
