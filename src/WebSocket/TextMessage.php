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

namespace KoolKode\Async\Http\WebSocket;

class TextMessage
{
    protected $text;

    public function __construct(string $text)
    {
        $this->text = $text;
    }

    public function __toString()
    {
        return $this->text;
    }

    public function getText(): string
    {
        return $this->text;
    }
}
