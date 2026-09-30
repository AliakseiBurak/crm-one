<?php

declare(strict_types=1);

namespace App\Service\Import;

/**
 * Приведение телефона к виду `+375 XX XXX-XX-XX` (двузначный код города) или
 * `+375 XXX XX-XX-XX` (трёхзначный).
 *
 * В выгрузке код города написан по-разному: `(17) 217-53-00`, `+375 (152)
 * 45-50-53`, `+375 177 70-87-32`, `8 029 626 99 94`, `8029…`, `29…`, иногда
 * вообще без указания кода. Номер приводится к одному виду, чтобы одна и та же
 * организация не показывала шесть записей одного телефона.
 *
 * Правила, взятые из данных, а не придуманные:
 *
 * - нация всегда девять цифр: код города 2 цифры + номер 7, либо код 3 цифры +
 *   номер 6 (в выгрузке 632 таких номера);
 * - длина кода берётся из самого текста, когда он написан — в скобках
 *   `(152)` или отдельно после `375` (`+375 177 …`); иначе считается, что код
 *   двухзначный, потому что таких подавляющее большинство;
 * - если после снятия префиксов нации не девять цифр, номер **не приводится
 *   вообще** и остаётся как есть. Дописывать и отбрасывать цифры нельзя: номер
 *   станет правдоподобным и неверным одновременно, и заметить это невозможно.
 */
final class PhoneNormalizer
{
    private const string COUNTRY_CODE = '375';

    /**
     * Число цифр в национальном номере — всегда одинаковое, различается только
     * то, где проходит граница «код города / номер».
     */
    private const int NATIONAL_DIGITS = 9;

    private const string CODE_PREFIX = '375';

    /**
     * Код города, если он выписан скобками: `(17)`, `(152)`. В скобках иногда
     * пишут с городским нулём — `(029)`, — это всё тот же двузначный код.
     */
    private const string CODE_IN_PARENS = '/\(0?(\d{2,3})\)/u';

    /**
     * Код города сразу за кодом страны: `+375 177 …`, `375 233 …`.
     * Разделитель обязателен: в слитном виде `375172799931` не видно, где
     * кончается код, и такой номер не трогаем — код по умолчанию двузначный.
     */
    private const string CODE_AFTER_COUNTRY = '/375[\s.-]+(\d{2,3})/u';

    /**
     * Приводит номер к канону либо возвращает его без изменений, если привести
     * не к чему. Ничего не выбрасывает и ничего не придумывает: сомнительный
     * номер остаётся в базе таким, каким прислали, — чтобы его увидел
     * менеджер, а не потерял.
     */
    public function normalize(string $phone): string
    {
        $trimmed = trim($phone);
        if ('' === $trimmed) {
            return $trimmed;
        }

        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';
        $code = $this->explicitCodeLength($trimmed);
        $national = $this->nationalDigits($digits);

        if (self::NATIONAL_DIGITS !== \strlen($national)) {
            return $trimmed;
        }

        $codeLength = $code ?? 2;
        if ($codeLength < 1 || $codeLength >= self::NATIONAL_DIGITS) {
            return $trimmed;
        }

        $cityCode = substr($national, 0, $codeLength);
        $local = substr($national, $codeLength);

        return \sprintf('+%s %s %s', self::COUNTRY_CODE, $cityCode, $this->groupLocal($local));
    }

    /**
     * Номер без кода страны и без префиксов набора: `8 029 …`, `017 …`,
     * `029 …` и `8029…` — это `+375 29 …`. Без префикса считаем, что код
     * страны уже опущен: `29 …` — это `+375 29 …`.
     */
    private function nationalDigits(string $digits): string
    {
        if (str_starts_with($digits, self::CODE_PREFIX)) {
            return substr($digits, 3);
        }
        if (str_starts_with($digits, '8')) {
            $digits = substr($digits, 1);
        }
        // Городской ноль: `8 029 …` уже снят выше, `029 …` — здесь.
        if (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return $digits;
    }

    /**
     * Длина кода города, если он выписан в самом номере.
     */
    private function explicitCodeLength(string $phone): ?int
    {
        if (1 === preg_match(self::CODE_IN_PARENS, $phone, $m)) {
            return \strlen($m[1]);
        }
        if (1 === preg_match(self::CODE_AFTER_COUNTRY, $phone, $m)) {
            return \strlen($m[1]);
        }

        return null;
    }

    /**
     * Номер без кода города: семь цифр — `XXX-XX-XX`, шесть — `XX-XX-XX`.
     */
    private function groupLocal(string $local): string
    {
        return match (\strlen($local)) {
            7 => \sprintf('%s-%s-%s', substr($local, 0, 3), substr($local, 3, 2), substr($local, 5, 2)),
            6 => \sprintf('%s-%s-%s', substr($local, 0, 2), substr($local, 2, 2), substr($local, 4, 2)),
            default => $local,
        };
    }
}
