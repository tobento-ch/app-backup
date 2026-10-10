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

namespace Tobento\App\Backup\Test;

trait ZipHelperTrait
{
    protected function createZipFileContent(string $filename, array $files = []): string
    {
        $tmp = sys_get_temp_dir() . '/' . uniqid('zip_', true);
        mkdir($tmp);

        $zipPath = $tmp . '/' . $filename;

        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);

        // Default file if none provided
        if (empty($files)) {
            $zip->addFromString('manifest.json', json_encode([
                'process_id' => 1,
                'name' => 'Upload Test',
                'created_at' => date('c'),
            ]));
        } else {
            foreach ($files as $name => $content) {
                $zip->addFromString($name, $content);
            }
        }

        $zip->close();

        // Read ZIP binary content
        $content = file_get_contents($zipPath);

        // Cleanup
        unlink($zipPath);
        rmdir($tmp);

        return $content;
    }
}