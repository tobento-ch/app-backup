<?php

/**
 * TOBENTO
 *
 * @copyright   Tobias Strub, TOBENTO
 * @license     MIT License, see LICENSE file distributed with this source code.
 * @author      Tobias Strub
 * @link        https://www.tobento.ch
 */

declare(strict_types=1);

namespace Tobento\App\Backup\Test\Feature\Artifact;

use Psr\Http\Message\StreamFactoryInterface;
use Tobento\App\AppInterface;
use Tobento\App\Backup\Artifact\Enums\FileAcceptDecision;
use Tobento\App\Backup\Artifact\ReadableZipArtifact;
use Tobento\App\Backup\Exception\FileNotFoundException;
use ZipArchive;

class ReadableZipArtifactTest extends \Tobento\App\Testing\TestCase
{
    protected string $tmpZipPath;

    public function createApp(): AppInterface
    {
        $app = $this->createTmpApp(rootDir: __DIR__.'/../../..');
        $app->boot(\Tobento\App\Backup\Boot\Backup::class);

        return $app;
    }

    protected function tearDown(): void
    {
        if (isset($this->tmpZipPath) && is_file($this->tmpZipPath)) {
            @unlink($this->tmpZipPath);
        }

        parent::tearDown();
    }

    /**
     * @param array<string, string> $entries relative-in-zip path => content
     */
    protected function createZip(array $entries): string
    {
        $this->tmpZipPath = sys_get_temp_dir().'/tobento-zip-test-'.uniqid().'.zip';

        $zip = new ZipArchive();
        $zip->open($this->tmpZipPath, ZipArchive::CREATE);

        if (empty($entries)) {
            // ZipArchive::close() can silently fail to write a truly
            // empty archive on some platforms - always add a throwaway
            // entry so the file genuinely exists on disk.
            $zip->addFromString('.gitkeep', '');
        }

        foreach ($entries as $path => $content) {
            $zip->addFromString($path, $content);
        }

        $zip->close();

        return $this->tmpZipPath;
    }

    protected function createArtifact(string $zipPath, int $throughputInBytes = 50_000_000): ReadableZipArtifact
    {
        $app = $this->bootingApp();
        $streamFactory = $app->get(StreamFactoryInterface::class);

        return new ReadableZipArtifact(
            streamFactory: $streamFactory,
            zipPath: $zipPath,
            id: 'backup.zip',
            throughputInBytes: $throughputInBytes,
        );
    }

    public function testGetterMethods()
    {
        $zipPath = $this->createZip([]);
        $artifact = $this->createArtifact(zipPath: $zipPath, throughputInBytes: 1000);
        
        $this->assertSame('backup.zip', $artifact->id());
        $this->assertSame($zipPath, $artifact->zipPath());
        $this->assertSame(1000, $artifact->throughputInBytes());
    }
    
    public function testCanReadFileMethodReturnsYesWhenWithinBudget()
    {
        $zipPath = $this->createZip([]);
        $artifact = $this->createArtifact(zipPath: $zipPath, throughputInBytes: 1000);

        $result = $artifact->canReadFile(
            filePath: 'a.txt',
            fileSize: 500,
            remainingSeconds: 10.0,
            timeBudgetInSeconds: 10.0,
        );

        $this->assertSame(FileAcceptDecision::Yes, $result->decision());
        $this->assertFalse($result->shouldThrow());
    }

    public function testCanReadFileMethodReturnsDeferWhenNotEnoughTimeRemainingInWindow()
    {
        $zipPath = $this->createZip([]);
        $artifact = $this->createArtifact(zipPath: $zipPath, throughputInBytes: 1000);

        $result = $artifact->canReadFile(
            filePath: 'a.txt',
            fileSize: 5000,
            remainingSeconds: 1.0,
            timeBudgetInSeconds: 10.0,
        );

        $this->assertSame(FileAcceptDecision::Defer, $result->decision());
        $this->assertFalse($result->shouldThrow());
    }

    public function testCanReadFileMethodReturnsNeverFitsWhenExceedsFullTimeBudget()
    {
        $zipPath = $this->createZip([]);
        $artifact = $this->createArtifact(zipPath: $zipPath, throughputInBytes: 1000);

        $result = $artifact->canReadFile(
            filePath: 'a.txt',
            fileSize: 50_000,
            remainingSeconds: 10.0,
            timeBudgetInSeconds: 10.0,
        );

        $this->assertSame(FileAcceptDecision::NeverFits, $result->decision());
        $this->assertTrue($result->shouldThrow());
    }

    public function testFilesMethodReturnsFilesUnderFolder()
    {
        $zipPath = $this->createZip([
            'backup/a.txt' => 'aaa',
            'backup/b.txt' => 'bbb',
            'other/c.txt' => 'ccc',
        ]);
        $artifact = $this->createArtifact($zipPath);

        $files = iterator_to_array($artifact->files(path: 'backup'));

        $this->assertCount(2, $files);

        $contents = [];
        foreach ($files as $file) {
            $contents[$file->path()] = $file->stream()->getContents();
        }

        // Note: unlike ReadableFileStorageArtifact, paths here are the
        // FULL in-zip paths, not relative to the folder filter.
        $this->assertSame('aaa', $contents['backup/a.txt'] ?? null);
        $this->assertSame('bbb', $contents['backup/b.txt'] ?? null);
        $this->assertArrayNotHasKey('other/c.txt', $contents);
    }

    public function testFilesMethodReturnsFullPathsNotRelative()
    {
        $zipPath = $this->createZip([
            'backup/sub/nested.txt' => 'nested',
        ]);
        $artifact = $this->createArtifact($zipPath);

        $files = iterator_to_array($artifact->files(path: 'backup', recursive: true));
        $paths = array_map(fn($f) => $f->path(), $files);

        // Confirms this class does NOT strip the folder prefix, unlike
        // ReadableFileStorageArtifact.
        $this->assertContains('backup/sub/nested.txt', $paths);
    }

    public function testFilesMethodRespectsRecursiveFalse()
    {
        $zipPath = $this->createZip([
            'backup/top.txt' => 'top',
            'backup/sub/nested.txt' => 'nested',
        ]);
        $artifact = $this->createArtifact($zipPath);

        $files = iterator_to_array($artifact->files(path: 'backup', recursive: false));
        $paths = array_map(fn($f) => $f->path(), $files);

        $this->assertContains('backup/top.txt', $paths);
        $this->assertNotContains('backup/sub/nested.txt', $paths);
    }

    public function testFilesMethodWithEmptyPathAndRecursiveFalseReturnsTopLevelFiles()
    {
        // Regression/edge-case test for the empty-prefix branch:
        // $relative = substr($filePath, strlen($prefix) + 1) with
        // $prefix === '' computes substr($filePath, 1), silently
        // dropping the file's first character before the '/' check.
        // Short top-level names may survive by luck; this test pins
        // down actual behavior rather than assuming it's correct.
        $zipPath = $this->createZip([
            'top.txt' => 'top',
            'sub/nested.txt' => 'nested',
        ]);
        $artifact = $this->createArtifact($zipPath);

        $files = iterator_to_array($artifact->files(path: '', recursive: false));
        $paths = array_map(fn($f) => $f->path(), $files);

        $this->assertContains('top.txt', $paths);
        $this->assertNotContains('sub/nested.txt', $paths);
    }

    public function testFileMethodReturnsMatchingFile()
    {
        $zipPath = $this->createZip([
            'backup/a.txt' => 'aaa',
        ]);
        $artifact = $this->createArtifact($zipPath);

        $file = $artifact->file('backup/a.txt');

        $this->assertSame('backup/a.txt', $file->path());
        $this->assertSame('aaa', $file->stream()->getContents());
        $this->assertSame(3, $file->size());
    }

    public function testFileMethodThrowsFileNotFoundExceptionWhenMissing()
    {
        $zipPath = $this->createZip([]);
        $artifact = $this->createArtifact($zipPath);

        $this->expectException(FileNotFoundException::class);

        $artifact->file('does-not-exist.txt');
    }
    
    public function testFileMethodIsCaseSensitive()
    {
        // file() and files() should agree: both are exact, case-sensitive
        // matches against the actual stored zip entry name. A path that
        // differs only in case should not be found by either.
        $zipPath = $this->createZip([
            'Backup/A.txt' => 'aaa',
        ]);
        $artifact = $this->createArtifact($zipPath);

        $this->expectException(FileNotFoundException::class);

        $artifact->file('backup/a.txt');
    }

    public function testFileMethodFindsExactCaseMatch()
    {
        $zipPath = $this->createZip([
            'Backup/A.txt' => 'aaa',
        ]);
        $artifact = $this->createArtifact($zipPath);

        $file = $artifact->file('Backup/A.txt');

        $this->assertSame('aaa', $file->stream()->getContents());
    }
}