<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Italix\Mvc;

use Psr\Http\Server\MiddlewareInterface;

abstract class BaseMiddleware implements MiddlewareInterface
{
    // Default fallback: no dependencies needed.
    public static function depends_on(string $method = ''): array
    {
        return [];
    }

    // Same constructor injection pattern as BaseController.
    public function __construct(array $services = [])
    {
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
