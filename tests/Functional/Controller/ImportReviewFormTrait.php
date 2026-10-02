<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use PHPUnit\Framework\Assert;

/**
 * Разбор формы пакета импорта для функциональных тестов.
 *
 * Форма вложенная — `rows[3][contacts][0][name]`, — и DomCrawler такие имена не
 * разбирает, поэтому тест собирает поля пакета прямо из отрисованной страницы.
 * Отрисованная форма, а не собранные руками данные: иначе тест утверждал бы пакет,
 * которого страница не показывала.
 *
 * Общее для обоих форматов: набор полей зависит от `sourceFormat` прогона, а
 * разбирается он одинаково.
 */
trait ImportReviewFormTrait
{
    /**
     * Поля формы ровно такие, какие отрисовала страница проверки.
     *
     * @return array<int, array<string, mixed>>
     */
    private function reviewRows(): array
    {
        $rows = [];
        $crawler = $this->client->getCrawler();

        foreach ($crawler->filter('input[name$="[rowNumber]"]') as $node) {
            \assert($node instanceof \DOMElement);
            $number = (int) $node->getAttribute('value');
            $prefix = 'rows[' . $number . ']';
            $values = [];

            foreach ($crawler->filter(\sprintf('*[name^="%s["]', $prefix)) as $field) {
                \assert($field instanceof \DOMElement);
                $name = $field->getAttribute('name');
                $key = substr($name, \strlen($prefix) + 1);
                $type = $field->getAttribute('type');

                if ('checkbox' === $type) {
                    $value = $field->hasAttribute('checked') ? '1' : '0';
                } elseif ('hidden' === $type) {
                    $value = $field->getAttribute('value');
                } else {
                    $value = $field->textContent !== '' ? $field->textContent : $field->getAttribute('value');
                }

                $this->assign($values, $this->splitName($key), $value);
            }

            $rows[$number] = $values;
        }

        Assert::assertNotEmpty($rows);

        return $rows;
    }

    /**
     * `contacts[0][name]` → ['contacts', 0, 'name']: имя поля формы надо
     * разобрать в массив, иначе PHP не построит вложенность из
     * `contacts][0][name]`.
     *
     * @return array<int, string|int>
     */
    private function splitName(string $key): array
    {
        preg_match_all('/^([A-Za-z_]+)|\[([^\]]+)\]/', $key, $matches, PREG_SET_ORDER);
        $parts = [];
        foreach ($matches as $match) {
            $part = '' !== ($match[1] ?? '') ? $match[1] : ($match[2] ?? '');
            $parts[] = ctype_digit($part) ? (int) $part : $part;
        }

        return $parts;
    }

    /**
     * @param array<string, mixed>   $target
     * @param array<int, string|int> $path
     */
    private function assign(array &$target, array $path, string $value): void
    {
        $cursor = &$target;
        $last = \count($path) - 1;
        foreach ($path as $index => $part) {
            if ($index === $last) {
                $cursor[$part] = $value;
                break;
            }
            if (!isset($cursor[$part]) || !\is_array($cursor[$part])) {
                $cursor[$part] = [];
            }
            $cursor = &$cursor[$part];
        }
    }
}
