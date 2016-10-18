<?php

/*
 * This file is part of KoolKode Async HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KoolKode\Async\Http;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

class TestLogger implements LoggerInterface, \Countable, \IteratorAggregate
{
    use LoggerTrait;

    protected $messages = [];

    /**
     * {@inheritdoc}
     */
    public function log($level, $message, array $context = [])
    {
        $this->messages[] = [
            'level' => $level,
            'message' => $message,
            'contexr' => $context
        ];
    }

    public function count()
    {
        return count($this->messages);
    }

    public function getIterator()
    {
        return new \ArrayIterator($this->messages);
    }
}
