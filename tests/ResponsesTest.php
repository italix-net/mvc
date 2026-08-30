<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Mvc — the PSR-17 seam
 *
 * The claim being tested is "this framework does not require one particular
 * PSR-7 implementation". The only way to test that is to supply a *different*
 * one and check that everything still comes back — so the fake below is not a
 * mock of the factory, it is a second PSR-7 implementation, deliberately
 * unrelated to Guzzle. If any code path still reached for Guzzle directly, the
 * assertions here would receive a Guzzle object and say so.
 *
 * The fake implements PSR-7 in full, so its method names are camelCase because
 * the specification says so, not because this file forgot the house rule.
 *
 * @external-api Psr\Http\Message\ResponseInterface
 *
 * Run: php src/Libs/Italix/Mvc/tests/ResponsesTest.php
 */

declare(strict_types=1);

(static function (): void {
    foreach ([
        __DIR__ . '/../vendor/autoload.php',               // checked out on its own
        __DIR__ . '/../../../../../vendor/autoload.php',   // vendored in a project
        __DIR__ . '/../../../../vendor/autoload.php',      // installed as a package
        __DIR__ . '/../../../autoload.php',                // sibling autoloader
    ] as $autoload) {
        if (is_file($autoload)) {
            require_once $autoload;

            return;
        }
    }

    fwrite(STDERR, "Could not find an autoloader. Run composer install.\n");
    exit(2);
})();

use Italix\Mvc\GuzzleResponseFactory;
use Italix\Mvc\Responses;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

use function Italix\Testing\{suite, section, test, summary};

suite('Italix Mvc — Responses');

/**
 * A PSR-7 response that is nobody else's.
 *
 * Written out rather than pulled from a package so that "not Guzzle" is a
 * property of this file and cannot quietly become "Guzzle again" through a
 * dependency update.
 */
final class PlainStream implements StreamInterface
{
    /** @var string */
    public $written = '';

    public function __toString(): string
    {
        return $this->written;
    }

    public function write($string): int
    {
        $this->written .= $string;

        return strlen((string) $string);
    }

    public function getContents(): string
    {
        return $this->written;
    }

    public function close(): void
    {
    }

    public function detach()
    {
        return null;
    }

    public function getSize(): ?int
    {
        return strlen($this->written);
    }

    public function tell(): int
    {
        return strlen($this->written);
    }

    public function eof(): bool
    {
        return true;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek($offset, $whence = SEEK_SET): void
    {
    }

    public function rewind(): void
    {
    }

    public function isWritable(): bool
    {
        return true;
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read($length): string
    {
        return $this->written;
    }

    /** @return mixed */
    public function getMetadata($key = null)
    {
        return null;
    }
}

final class PlainResponse implements ResponseInterface
{
    /** @var int */
    private $status;

    /** @var array<string, string[]> */
    private $headers = [];

    /** @var PlainStream */
    private $body;

    public function __construct(int $status)
    {
        $this->status = $status;
        $this->body   = new PlainStream();
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function withStatus($code, $reasonPhrase = ''): self
    {
        $clone         = clone $this;
        $clone->status = (int) $code;

        return $clone;
    }

    public function getReasonPhrase(): string
    {
        return '';
    }

    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    public function withHeader($name, $value): self
    {
        $clone                              = clone $this;
        $clone->headers[strtolower($name)]  = (array) $value;

        return $clone;
    }

    public function getHeaderLine($name): string
    {
        return implode(', ', $this->headers[strtolower($name)] ?? []);
    }

    public function hasHeader($name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    /** @return array<string, string[]> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /** @return string[] */
    public function getHeader($name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }

    public function withAddedHeader($name, $value): self
    {
        return $this->withHeader($name, $value);
    }

    public function withoutHeader($name): self
    {
        $clone = clone $this;
        unset($clone->headers[strtolower($name)]);

        return $clone;
    }

    public function withBody(StreamInterface $body): self
    {
        return $this;
    }

    public function getProtocolVersion(): string
    {
        return '1.1';
    }

    public function withProtocolVersion($version): self
    {
        return $this;
    }
}

final class PlainFactory implements ResponseFactoryInterface
{
    /** @var int how many responses this factory was asked for */
    public $made_n = 0;

    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
    {
        $this->made_n++;

        return new PlainResponse($code);
    }
}

// -----------------------------------------------------------------------------
section('the default, when nobody chose');

Responses::reset();

test('there is a factory without configuring one', Responses::factory() instanceof ResponseFactoryInterface);
test('…and it is the bundled one', Responses::factory() instanceof GuzzleResponseFactory);
test('is_custom() says nobody has taken over', !Responses::is_custom());

$response = Responses::make(201, 'hello');

test('make() sets the status', $response->getStatusCode() === 201);
test('make() writes the body', (string) $response->getBody() === 'hello');
test('make() with no body leaves it empty', (string) Responses::make()->getBody() === '');
test('make() defaults to 200', Responses::make()->getStatusCode() === 200);

$redirect = Responses::redirect('/it/admin/index.html');

test('redirect() sets Location', $redirect->getHeaderLine('Location') === '/it/admin/index.html');
test('redirect() defaults to 302', $redirect->getStatusCode() === 302);
test('redirect() takes another status', Responses::redirect('/x', 301)->getStatusCode() === 301);

$typed = Responses::with_type('{"a":1}', 'application/json');

test('with_type() sets the header', $typed->getHeaderLine('Content-Type') === 'application/json');
test('…and keeps the body', (string) $typed->getBody() === '{"a":1}');

// -----------------------------------------------------------------------------
section('another PSR-7 implementation entirely');

// The point of the seam. If any of these still come back as a Guzzle object,
// something is constructing responses behind the factory's back.
$factory = new PlainFactory();
Responses::use_factory($factory);

test('the chosen factory is the one in effect', Responses::factory() === $factory);
test('is_custom() notices', Responses::is_custom());

$made = Responses::make(418, 'teapot');

test('MAKE() RETURNS THE FOREIGN IMPLEMENTATION, not Guzzle',
    $made instanceof PlainResponse, get_class($made));
test('…with the status asked for', $made->getStatusCode() === 418);
test('…and the body written through its own stream', (string) $made->getBody() === 'teapot');

$foreign_redirect = Responses::redirect('/somewhere', 303);

test('redirect() goes through the factory too', $foreign_redirect instanceof PlainResponse);
test('…and still sets Location', $foreign_redirect->getHeaderLine('Location') === '/somewhere');

$foreign_typed = Responses::with_type('x,y', 'text/csv', 200);

test('with_type() goes through the factory too', $foreign_typed instanceof PlainResponse);
test('…and still sets the type', $foreign_typed->getHeaderLine('Content-Type') === 'text/csv');

test('every response came from the factory, none from anywhere else',
    $factory->made_n === 3, 'the factory made ' . $factory->made_n . ' of 3');

// -----------------------------------------------------------------------------
section('a controller builds its responses the same way');

// The seam is worth nothing if BaseController still reaches past it, and that
// is exactly what it did before this change — `new Response($status)` twice.
final class ProbeController extends \Italix\Mvc\BaseController
{
    public function make_redirect(): ResponseInterface
    {
        return $this->redirect_to('/it/home.html');
    }

    /** @param mixed $data */
    public function make_json($data): ResponseInterface
    {
        return $this->json($data);
    }
}

$controller = new ProbeController();

test('REDIRECT_TO() USES THE FACTORY',
    $controller->make_redirect() instanceof PlainResponse,
    get_class($controller->make_redirect()));

$json = $controller->make_json(['ok' => true]);

test('JSON() USES THE FACTORY', $json instanceof PlainResponse, get_class($json));
test('…and still encodes', (string) $json->getBody() === '{"ok":true}');
test('…and still sets the content type', $json->getHeaderLine('Content-Type') === 'application/json');
test('json() passes a pre-encoded string through untouched',
    (string) $controller->make_json('{"raw":1}')->getBody() === '{"raw":1}');

// -----------------------------------------------------------------------------
section('reset() puts it back');

Responses::reset();

test('reset() restores the default', Responses::factory() instanceof GuzzleResponseFactory);
test('…and is_custom() agrees again', !Responses::is_custom());

exit(summary());
