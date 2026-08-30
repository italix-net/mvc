<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Italix\Mvc;

use Psr\Container\ContainerInterface;

/**
 * Lazily-resolved services, declared as a map of key => factory in conf.php.
 *
 * Implements PSR-11 so that anything speaking that interface — `Italix\Console`
 * resolving a command's dependencies, a third-party library, a test double —
 * can be handed this registry without knowing what Italix is. `psr/container`
 * is an interface-only package, so the interop costs nothing at runtime and
 * nothing in the dependency count (house rule 12).
 *
 * A factory is any callable; anything else is treated as an already-built
 * value. Resolution happens once per key and is cached.
 */
class ServiceRegistry implements ContainerInterface
{
    private array $resolved = [];
    private array $factories;

    public function __construct(array $factories)
    {
        $this->factories = $factories;
    }

    /**
     * @param  string $key
     * @return mixed
     *
     * @throws ServiceNotFound when nothing is registered under $key
     */
    public function get(string $key)
    {
        if (!array_key_exists($key, $this->resolved)) {
            if (!array_key_exists($key, $this->factories)) {
                throw new ServiceNotFound("No service registered for \"{$key}\".");
            }

            $factory = $this->factories[$key];
            $this->resolved[$key] = is_callable($factory) ? $factory($this) : $factory;
        }

        return $this->resolved[$key];
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->factories);
    }

    /**
     * The registered keys, for diagnostics — `ix list` and the dev toolbar.
     *
     * @return string[]
     */
    public function keys(): array
    {
        return array_keys($this->factories);
    }
}
