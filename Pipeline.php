<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Italix\Mvc;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class Pipeline
{
    /**
     * Wrap a stack of middleware around a final handler and return the outermost handler.
     * The first entry in $middlewares is the outermost layer — it receives the request first
     * and sees the response last.
     *
     * @param MiddlewareInterface[] $middlewares
     */
    public static function build(array $middlewares, RequestHandlerInterface $final): RequestHandlerInterface
    {
        $handler = $final;

        foreach (array_reverse($middlewares) as $middleware) {
            $handler = new MiddlewareHandler($middleware, $handler);
        }

        return $handler;
    }
}
