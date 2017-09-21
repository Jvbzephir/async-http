<?php

/*
 * This file is part of KoolKode Async HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace KoolKode\Async\Http\Response;

use KoolKode\Async\Http\Http;
use KoolKode\Async\Http\HttpResponse;
use KoolKode\Async\Http\Body\FileBody;
use KoolKode\Async\Http\Header\ContentType;
use KoolKode\Util\Filesystem;

/**
 * HTTP response that transfers contents of file.
 * 
 * @author Martin Schröder
 */
class FileResponse extends HttpResponse
{
    /**
     * Absolute path of the file being sent.
     * 
     * @var string
     */
    protected $file;

    /**
     * Create HTTP file response.
     * 
     * @param string $file Absolute path of the file to be transfered.
     * @param string $type Media type of the file (will be guessed from extension if not provided).
     * @param string $charset Charset to be supplied when media type is text format.
     */
    public function __construct(string $file, ?string $type = null, string $charset = null)
    {
        $type = new ContentType($type ?? Filesystem::guessMimeTypeFromFilename($file));
        
        if ($type->getMediaType()->isText()) {
            $type->setParam('charset', $charset ?? 'utf-8');
        }
        
        parent::__construct(Http::OK, [
            'Content-Type' => (string) $type
        ]);
        
        $this->body = new FileBody($file);
        $this->file = $file;
    }

    /**
     * Add a Content-Dispotion header that can be used to have Browsers ask the user to download the received file.
     * 
     * @param string $filename Filename suggested to the user (defaults to basename of the transfered file).
     * @return FileResponse
     */
    public function withContentDisposition(?string $filename = null): FileResponse
    {
        return $this->withHeader('Content-Disposition', \sprintf('attachment;filename="%s"', $filename ?? \basename($this->file)));
    }
}
