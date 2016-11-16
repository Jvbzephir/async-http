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

namespace KoolKode\Async\Http\Responder;

trait ResponderSupported
{
    protected $responders = [];

    public function addResponder(callable $responder, int $priority = null)
    {
        if ($priority === null) {
            if ($responder instanceof Responder) {
                $priority = $responder->getDefaultPriority();
            } else {
                $priority = 0;
            }
        }
        
        for ($size = \count($this->responders), $i = 0; $i < $size; $i++) {
            if ($this->responders[$i]->priority < $priority) {
                \array_splice($this->responders, $i, 0, [
                    new RegisteredResponder($responder, $priority)
                ]);
                
                return;
            }
        }
        
        $this->responders[] = new RegisteredResponder($responder, $priority);
    }
}
