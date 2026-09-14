<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Файловое хранилище вложений рассылок (tasks 8.1/8.2 change campaign-entity):
 * файл лежит в var/storage/campaign-attachments/<storageKey>, в БД — метаданные.
 * Удаление файла — при удалении вложения или кампании (после успешного flush).
 */
final class CampaignAttachmentStorage
{
    private const string DIRECTORY = 'var/storage/campaign-attachments';

    public function __construct(
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {}

    /**
     * Сохраняет загруженный файл и возвращает сгенерированный ключ хранилища.
     */
    public function store(UploadedFile $file): string
    {
        $directory = $this->directory();
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new FileException(\sprintf('Не удалось создать каталог хранилища "%s"', $directory));
        }
        /** @noinspection PhpUnhandledExceptionInspection */
        $storageKey = bin2hex(random_bytes(16));
        $file->move($directory, $storageKey);

        return $storageKey;
    }

    public function delete(string $storageKey): void
    {
        $path = $this->path($storageKey);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function path(string $storageKey): string
    {
        return $this->directory() . \DIRECTORY_SEPARATOR . $storageKey;
    }

    private function directory(): string
    {
        return $this->projectDir . \DIRECTORY_SEPARATOR . self::DIRECTORY;
    }
}
