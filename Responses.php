<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Mvc - response construction
 *
 * @package Italix\Mvc
 */

declare(strict_types=1);

namespace Italix\Mvc;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The one place this framework builds a response.
 *
 * PSR-7 says what a response *is*; it says nothing about how to make one, which
 * is why PSR-17 exists. Without a factory, `new GuzzleHttp\Psr7\Response(...)`
 * appears in six files and the framework has quietly required Guzzle — not the
 * interfaces, the implementation. An application that already ships
 * `nyholm/psr7`, which many do because it is a fraction of the size and is
 * often what a host mandates, then carries two PSR-7 implementations to use
 * one framework.
 *
 * So responses come from a factory, and the factory is replaceable:
 *
 *     Responses::use_factory(new \Nyholm\Psr7\Factory\Psr17Factory());
 *
 * Nothing else changes. Guzzle stays the default because it is already a
 * dependency and because a framework that refuses to start until you have
 * chosen a PSR-7 implementation has made the first five minutes worse for
 * everybody in order to please a minority.
 *
 * ## Why a static holder rather than injection
 *
 * Because the alternative is threading a factory through `BaseController`,
 * every subclass constructor, and the error handler that runs when construction
 * itself failed — for a decision an application makes once at boot and never
 * revisits. `Session` and `T` are the same shape for the same reason.
 *
 * On a resident worker this needs no per-request rebinding, unlike those two:
 * the factory is a property of the deployment, not of the request. `reset()`
 * exists for tests, which are the only caller with a reason to change it twice
 * in one process.
 */
final class Responses
{
    /** @var ResponseFactoryInterface|null */
    private static $factory;

    private function __construct()
    {
    }

    /**
     * Build every response from here instead.
     *
     * Call it once, at boot, before the first request is handled.
     */
    public static function use_factory(ResponseFactoryInterface $factory): void
    {
        self::$factory = $factory;
    }

    /** Back to the bundled default. For tests. */
    public static function reset(): void
    {
        self::$factory = null;
    }

    /** The factory in effect, whether it was chosen or defaulted. */
    public static function factory(): ResponseFactoryInterface
    {
        if (self::$factory === null) {
            self::$factory = new GuzzleResponseFactory();
        }

        return self::$factory;
    }

    /** Is somebody else's factory in charge? */
    public static function is_custom(): bool
    {
        return self::$factory !== null && !self::$factory instanceof GuzzleResponseFactory;
    }

    /**
     * A response with a status and, optionally, a body already written.
     *
     * The convenience that made `new Response($status)` attractive in the first
     * place: PSR-17's own `createResponse()` returns an empty body and leaves
     * the two-step dance to the caller, every time.
     */
    public static function make(int $status = 200, string $body = ''): ResponseInterface
    {
        $response = self::factory()->createResponse($status);

        if ($body !== '') {
            $response->getBody()->write($body);
        }

        return $response;
    }

    /** A redirect. */
    public static function redirect(string $url, int $status = 302): ResponseInterface
    {
        return self::factory()->createResponse($status)->withHeader('Location', $url);
    }

    /** A response with a body and a content type. */
    public static function with_type(string $body, string $type_c, int $status = 200): ResponseInterface
    {
        return self::make($status, $body)->withHeader('Content-Type', $type_c);
    }
}
