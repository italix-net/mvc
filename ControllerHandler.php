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
use Psr\Http\Server\RequestHandlerInterface;

// Terminal handler in the route middleware pipeline — invokes the resolved controller method.
class ControllerHandler implements RequestHandlerInterface
{
    /** @var object */
    private $controller;

    /** @var string */
    private $method_name;

    // Written out rather than promoted: promotion is PHP 8.0, and two short
    // classes are not worth raising this package's floor for.
    public function __construct(object $controller, string $method_name)
    {
        $this->controller  = $controller;
        $this->method_name = $method_name;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->controller->{$this->method_name}($request);
    }
}
