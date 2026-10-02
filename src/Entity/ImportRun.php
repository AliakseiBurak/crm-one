<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ImportRunRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Прогон импорта организаций (design D2).
 *
 * Это запись в БД, а не PHP-сессия: между запросами на сервере не передаётся
 * ничего. Идентификатор прогона приходит в URL, формат источника, прогресс и
 * файл читаются из самой записи. Статуса и второго счётчика нет: прогресс — это
 * `processedRows` против `totalRows`, а `processedRows` считает только
 * сохранённые строки. Импорт завершён, когда processedRows >= totalRows.
 */
#[ORM\Entity(repositoryClass: ImportRunRepository::class)]
#[ORM\Table(name: 'import_run')]
class ImportRun
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    /**
     * Имя файла, показанное администратору; относится к текущему источнику
     * прогона.
     */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Имя файла обязательно для заполнения')]
    public private(set) string $filename = '';

    /**
     * Ключ хранилища файла, который реально разбирается (design D5).
     */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Ключ файла обязателен для заполнения')]
    public private(set) string $storageKey = '';

    /**
     * Формат источника. Колонка создаётся этой миграцией, чтобы второму
     * формату (change add-organizations-json-import) миграция не понадобилась.
     * В списке импортов не отображается.
     */
    #[ORM\Column(name: 'source_format', length: 32, options: ['default' => 'csv'])]
    public private(set) string $sourceFormat = self::SOURCE_FORMAT_CSV;

    /**
     * Число непустых записей источника — строк организаций, без заголовка.
     */
    #[ORM\Column(name: 'total_rows')]
    public private(set) int $totalRows = 0;

    /**
     * Число сохранённых строк. Пропуска строк нет: строка, которую не удалось
     * сохранить, останавливает прогон и не двигает счётчик.
     */
    #[ORM\Column(name: 'processed_rows')]
    public private(set) int $processedRows = 0;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    public private(set) \DateTimeImmutable $createdAt;

    /**
     * Момент сохранения последней строки прогона; null, пока строки не
     * сохранялись. В списке импортов это колонка «дата последней
     * обработанной строки».
     */
    #[ORM\Column(name: 'last_processed_at', type: 'datetime_immutable', nullable: true)]
    public private(set) ?\DateTimeImmutable $lastProcessedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    public private(set) ?User $createdBy = null;

    public const string SOURCE_FORMAT_CSV = 'csv';

    /**
     * Прогон создан из ответа языковой модели (change
     * add-organizations-json-import). Колонка не меняется: значение выбирает
     * парсер и способ сравнения строк при замене файла.
     */
    public const string SOURCE_FORMAT_JSON = 'json';

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function setFilename(string $filename): self
    {
        $this->filename = $filename;

        return $this;
    }

    public function setStorageKey(string $storageKey): self
    {
        $this->storageKey = $storageKey;

        return $this;
    }

    public function setSourceFormat(string $sourceFormat): self
    {
        $this->sourceFormat = $sourceFormat;

        return $this;
    }

    public function setTotalRows(int $totalRows): self
    {
        $this->totalRows = $totalRows;

        return $this;
    }

    public function setProcessedRows(int $processedRows): self
    {
        $this->processedRows = $processedRows;

        return $this;
    }

    public function setCreatedBy(?User $createdBy): self
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    /**
     * Строка сохранена: счётчик продвигается, «дата последней обработанной
     * строки» обновляется.
     */
    public function markRowProcessed(): self
    {
        ++$this->processedRows;
        $this->lastProcessedAt = new \DateTimeImmutable();

        return $this;
    }

    /**
     * Прогон завершён, когда обработанных строк не меньше, чем строк в файле.
     * Строгое равенство не используется: после замены файла на более короткий
     * processedRows может превысить totalRows, и такой прогон считается
     * завершённым, а не зависшим.
     *
     * Ответ зависит от изменяемого состояния: `markRowProcessed()` меняет его,
     * поэтому статический анализ не считает результат закешированным.
     *
     * @phpstan-impure
     */
    public function isFinished(): bool
    {
        return $this->processedRows >= $this->totalRows;
    }
}
