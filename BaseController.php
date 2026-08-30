<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
// src/Libs/Italix/Mvc/BaseController.php

declare(strict_types=1);

namespace Italix\Mvc;

use Italix\Contracts\DataContainer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

abstract class BaseController
{
    protected DataContainer $context;

    protected function context(): DataContainer
    {
        return $this->context;
    }

    protected function input(ServerRequestInterface $request): RequestInput
    {
        return new RequestInput($request);
    }

    protected function redirect_to(string $url, int $status = 302): ResponseInterface
    {
        return Responses::redirect($url, $status);
    }

    /**
     * Return a JSON response. $data may be a pre-encoded string or any
     * value that will be passed through json_encode().
     */
    /** @param mixed $data */
    protected function json($data, int $status = 200): ResponseInterface
    {
        $body = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return Responses::with_type((string) $body, 'application/json', $status);
    }

    // Default fallback: If a controller doesn't define this, it needs nothing.
    public static function depends_on(string $method = ''): array
    {
        return [];
    }

    // The magic constructor that maps the array to the properties
    public function __construct(array $services = [])
    {
        $this->context = new DataContainer();

        foreach ($services as $property_name => $service_instance)
        {
            if (!property_exists($this, $property_name)) {
                throw new \LogicException(
                    static::class . "::depends_on() declares '\$$property_name' but the property is not defined."
                );
            }

            $this->$property_name = $service_instance;
        }
    }
}
