<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Italix\Mvc;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

// Wraps one middleware layer around the next handler in the pipeline.
class MiddlewareHandler implements RequestHandlerInterface
{
    /** @var MiddlewareInterface */
    private $middleware;

    /** @var RequestHandlerInterface */
    private $next;

    // Written out rather than promoted: promotion is PHP 8.0, and two short
    // classes are not worth raising this package's floor for.
    public function __construct(MiddlewareInterface $middleware, RequestHandlerInterface $next)
    {
        $this->middleware = $middleware;
        $this->next       = $next;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->middleware->process($request, $this->next);
    }
}
