<?php

declare(strict_types=1);

namespace App\Service\Import;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Файловое хранилище файлов импорта организаций (design D5): файл лежит в
 * var/storage/imports/<storageKey>, в БД — метаданные прогона.
 *
 * Каталог называется `imports`, а не `csv-imports`: сохранённый payload не
 * привязан к породившему его формату (вставленный текст пишется на диск
 * ровно так же, как загруженный файл), а замена файла прогона (design D8)
 * читает прежний payload независимо от того, как он был записан.
 *
 * Текущий файл прогона импортом не удаляется: он нужен для продолжения после
 * остановки и для сверки при замене. Единственное место, где хранилище
 * удаляет файл, — подтверждённая замена файла прогона.
 */
final class ImportFileStorage
{
    private const string DIRECTORY = 'var/storage/imports';

    public function __construct(
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {}

    /**
     * Сохраняет загруженный файл и возвращает сгенерированный ключ хранилища.
     */
    public function store(UploadedFile $file): string
    {
        $this->ensureDirectory();
        $storageKey = $this->generateKey();
        $file->move($this->directory(), $storageKey);

        return $storageKey;
    }

    /**
     * Сохраняет содержимое как есть (вставленный текст) и возвращает ключ.
     */
    public function storeContents(string $contents): string
    {
        $this->ensureDirectory();
        $storageKey = $this->generateKey();
        $path = $this->path($storageKey);
        if (false === @file_put_contents($path, $contents)) {
            throw new FileException(\sprintf('Не удалось сохранить файл импорта "%s"', $path));
        }

        return $storageKey;
    }

    public function delete(string $storageKey): void
    {
        $path = $this->path($storageKey);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Содержимое сохранённого файла.
     *
     * Отчёт о замене и повторная проверка кандидата читают файл, а не держат его
     * в памяти между запросами: кандидат едет в адресе, и по этому адресу отчёт
     * должен воспроизводиться заново (design D8).
     *
     * @throws FileException файла нет или он не читается
     */
    public function read(string $storageKey): string
    {
        $path = $this->path($storageKey);
        $contents = is_file($path) ? @file_get_contents($path) : false;
        if (false === $contents) {
            throw new FileException(\sprintf('Не удалось прочитать файл импорта "%s"', $path));
        }

        return $contents;
    }

    public function path(string $storageKey): string
    {
        return $this->directory() . \DIRECTORY_SEPARATOR . $storageKey;
    }

    private function ensureDirectory(): void
    {
        $directory = $this->directory();
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new FileException(\sprintf('Не удалось создать каталог хранилища "%s"', $directory));
        }
    }

    /**
     * @noinspection PhpUnhandledExceptionInspection
     */
    private function generateKey(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function directory(): string
    {
        return $this->projectDir . \DIRECTORY_SEPARATOR . self::DIRECTORY;
    }
}
