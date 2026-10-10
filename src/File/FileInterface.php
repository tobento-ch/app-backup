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
 
namespace Tobento\App\Backup\File;

use Psr\Http\Message\StreamInterface;

interface FileInterface
{
    /**
     * Returns the path.
     *
     * @return string
     */
    public function path(): string;
    
    /**
     * Returns the name.
     *
     * @return string
     */
    public function name(): string;
    
    /**
     * Returns the filename.
     *
     * @return string
     */
    public function filename(): string;
    
    /**
     * Returns the extension.
     *
     * @return string
     */
    public function extension(): string;
    
    /**
     * Returns the folder path.
     *
     * @return string
     */
    public function folderPath(): string;
    
    /**
     * Returns the stream.
     *
     * @return StreamInterface
     */
    public function stream(): StreamInterface;
    
    /**
     * Returns the mime type.
     *
     * @return string
     */
    public function mimeType(): string;
    
    /**
     * Returns the size.
     *
     * @return int|float
     */
    public function size(): int|float;
}