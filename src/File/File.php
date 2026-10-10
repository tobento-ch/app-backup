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

use Closure;
use Psr\Http\Message\StreamInterface;

final class File implements FileInterface
{
    /**
     * @var string
     */
    private string $name;
    
    /**
     * @var string
     */
    private string $filename;
    
    /**
     * @var string
     */
    private string $extension;
    
    /**
     * Create a new instance.
     *
     * @param string $path
     * @param StreamInterface|Closure $stream
     * @param string $mimeType
     * @param int|float $size
     */
    public function __construct(
        private string $path,
        private StreamInterface|Closure $stream,
        private string $mimeType,
        private int|float $size,
    ) {
        $pathinfo = pathinfo($path);
        $this->name = $pathinfo['basename'] ?? '';
        $this->filename = $pathinfo['filename'] ?? '';
        $this->extension = isset($pathinfo['extension']) ? strtolower($pathinfo['extension']) : '';        
    }

    /**
     * Returns the path.
     *
     * @return string
     */
    public function path(): string
    {
        return $this->path;
    }
    
    /**
     * Returns the name.
     *
     * @return string
     */
    public function name(): string
    {
        return $this->name;
    }
    
    /**
     * Returns the filename.
     *
     * @return string
     */
    public function filename(): string
    {
        return $this->filename;
    }
    
    /**
     * Returns the extension.
     *
     * @return string
     */
    public function extension(): string
    {
        return $this->extension;
    }
    
    /**
     * Returns the folder path.
     *
     * @return string
     */
    public function folderPath(): string
    {
        $dirname = dirname($this->path);
        
        return $dirname === '.' ? '' : $dirname;
    }
    
    /**
     * Returns the stream.
     *
     * @return StreamInterface
     */
    public function stream(): StreamInterface
    {
        if ($this->stream instanceof StreamInterface) {
            return $this->stream;
        }

        $this->stream = ($this->stream)();

        if (! $this->stream instanceof StreamInterface) {
            throw new \RuntimeException('Stream callback must return StreamInterface');
        }

        return $this->stream;
    }
    
    /**
     * Returns the mime type.
     *
     * @return string
     */
    public function mimeType(): string
    {
        return $this->mimeType;
    }
    
    /**
     * Returns the size.
     *
     * @return int|float
     */
    public function size(): int|float
    {
        return $this->size;
    }
}