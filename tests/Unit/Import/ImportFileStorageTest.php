<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import;

use App\Service\Import\ImportFileStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ImportFileStorageTest extends TestCase
{
    private string $projectDir;

    private ImportFileStorage $storage;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/import-storage-test-' . bin2hex(random_bytes(6));
        (new Filesystem())->mkdir($this->projectDir . '/var/storage/imports');
        $this->storage = new ImportFileStorage($this->projectDir);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    public function testStoredUploadIsReadableByteForByte(): void
    {
        $contents = "Компания,Актуальный курс\r\n\"Нафтан\",КП\n";
        $source = $this->projectDir . '/upload.csv';
        file_put_contents($source, $contents);

        $storageKey = $this->storage->store(new UploadedFile($source, 'База.csv', 'text/csv', null, true));
        $path = $this->storage->path($storageKey);

        self::assertFileExists($path);
        self::assertSame($contents, file_get_contents($path));
        self::assertSame(
            $this->projectDir . '/var/storage/imports/' . $storageKey,
            $path,
        );
    }

    public function testStoredContentsAreReadableByteForByte(): void
    {
        $contents = "Компания,Актуальный курс\r\n\"Белсвязьстрой\",КП\r\n";

        $storageKey = $this->storage->storeContents($contents);
        $path = $this->storage->path($storageKey);

        self::assertFileExists($path);
        self::assertSame($contents, file_get_contents($path));
    }

    public function testEachStoreGetsItsOwnKey(): void
    {
        $first = $this->storage->storeContents('a');
        $second = $this->storage->storeContents('b');

        self::assertNotSame($first, $second);
        self::assertFileExists($this->storage->path($first));
        self::assertFileExists($this->storage->path($second));
    }

    public function testDeleteRemovesExactlyTheGivenFile(): void
    {
        $kept = $this->storage->storeContents('kept');
        $removed = $this->storage->storeContents('removed');

        $this->storage->delete($removed);

        self::assertFileDoesNotExist($this->storage->path($removed));
        self::assertFileExists($this->storage->path($kept));
    }

    public function testDeleteOfMissingFileIsNoOp(): void
    {
        $this->storage->delete('does-not-exist');

        self::assertFileDoesNotExist($this->storage->path('does-not-exist'));
    }
}
