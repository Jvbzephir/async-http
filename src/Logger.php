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

namespace KoolKode\Async\Http;

use KoolKode\Async\Log\LoggerProxy;

/**
 * Adds the KoolKode HTTP log origin.
 *
 * @author Martin Schröder
 */
class Logger extends LoggerProxy
{
    /**
     * {@inheritdoc}
     */
    protected function getAdditionalOrigins(): array
    {
        return \array_merge(parent::getAdditionalOrigins(), [
            'koolkode/async-http'
        ]);
    }
}
