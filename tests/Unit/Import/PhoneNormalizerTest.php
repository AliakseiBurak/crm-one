<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import;

use App\Service\Import\PhoneNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhoneNormalizerTest extends TestCase
{
    private PhoneNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new PhoneNormalizer();
    }

    #[DataProvider('provideCanonicalFormCases')]
    public function testCanonicalForm(string $input, string $expected): void
    {
        self::assertSame($expected, $this->normalizer->normalize($input), $input);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideCanonicalFormCases(): iterable
    {
        // `8 017` и `8017` — один и тот же код города: восьмёрка и ноль
        // отбрасываются.
        yield '8 017 разделители' => ['8 (017) 212-34-56', '+375 17 212-34-56'];
        yield '8017 слитно' => ['8017 212-34-56', '+375 17 212-34-56'];
        yield 'код без восьмёрки' => ['(29) 212-34-56', '+375 29 212-34-56'];
        yield 'с кодом оператора' => ['+375 17 212-34-56', '+375 17 212-34-56'];
        yield '375 без плюса' => ['375 29 2123456', '+375 29 212-34-56'];
        yield 'мобильный с восьмёркой' => ['8 029 212-34-56', '+375 29 212-34-56'];
        yield 'слепки' => ['+375292123456', '+375 29 212-34-56'];
    }

    /**
     * В выгрузке код города бывает трёхзначным: `152`, `177`, `212`. Он
     * читается из самого номера, когда написан скобками или отделён от кода
     * страны, — тогда подписная часть короче на одну цифру.
     *
     * @return iterable<string, array{string, string}>
     */
    #[DataProvider('provideThreeDigitCityCodeCases')]
    public function testThreeDigitCityCode(string $input, string $expected): void
    {
        self::assertSame($expected, $this->normalizer->normalize($input), $input);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideThreeDigitCityCodeCases(): iterable
    {
        yield 'код в скобках' => ['+375 (152) 45-50-53', '+375 152 45-50-53'];
        yield 'код через 375' => ['+375 177 70-87-32', '+375 177 70-87-32'];
        yield 'код скобками без плюса' => ['(214) 59-81-05', '+375 214 59-81-05'];
        yield 'мобильный оператор' => ['+375 212 55 48 19', '+375 212 55-48-19'];
        yield 'через восьмёрку' => ['8 (029) 650-32-44', '+375 29 650-32-44'];
        yield 'городской ноль в скобках' => ['(029) 650-32-44', '+375 29 650-32-44'];
        yield 'без разделителей код не угадывается' => ['375172799931', '+375 17 279-99-31'];
    }

    /**
     * Нормализация не придумывает и не отбрасывает цифры: если после
     * упрощения набирается не девять цифр, значение остаётся как было.
     *
     * @return iterable<string, array{string}>
     */
    #[DataProvider('provideNothingIsInventedOrDroppedCases')]
    public function testNothingIsInventedOrDropped(string $input): void
    {
        self::assertSame($input, $this->normalizer->normalize($input), $input);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideNothingIsInventedOrDroppedCases(): iterable
    {
        yield 'семь цифр без кода' => ['212-34-56'];
        yield 'восемь цифр' => ['9212-34-56'];
        yield 'десять цифр' => ['8 017 9212-3456'];
        yield 'одна цифра' => ['8'];
        yield 'не телефон' => ['позвонить в офис'];
    }

    public function testEmptyAndWhitespaceAreTrimmedToEmpty(): void
    {
        // Пустое значение вызывающий код превращает в null, поэтому
        // нормализатору достаточно вернуть пустую строку.
        self::assertSame('', $this->normalizer->normalize(''));
        self::assertSame('', $this->normalizer->normalize('  '));
    }

    public function testResultFitsTheContactPhoneColumn(): void
    {
        $normalized = $this->normalizer->normalize('8 017 212-34-56');

        self::assertLessThanOrEqual(32, mb_strlen($normalized));
    }
}
