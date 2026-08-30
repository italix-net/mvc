<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
// src/Libs/Italix/Mvc/RequestInput.php

declare(strict_types=1);

namespace Italix\Mvc;

use Italix\Contracts\DataContainer;
use Psr\Http\Message\ServerRequestInterface;

class RequestInput
{
    /** @var ServerRequestInterface */
    private $request;

    public function __construct(ServerRequestInterface $request)
    {
        $this->request = $request;
    }

    /**
     * Access query string (GET) parameters.
     *
     * get()                         → DataContainer of all query params
     * get('key', $default)          → single value or $default
     * get(['key' => $default, ...]) → DataContainer of requested keys, defaults applied
     *
     * @param string|array|null $key
     * @param mixed $default
     * @return mixed
     */
    public function get($key = null, $default = null)
    {
        $params = $this->request->getQueryParams();

        if ($key === null) {
            return new DataContainer($params);
        }

        if (is_array($key)) {
            $result = [];
            foreach ($key as $k => $default_val) {
                $result[$k] = array_key_exists($k, $params) ? $params[$k] : $default_val;
            }
            return new DataContainer($result);
        }

        return array_key_exists($key, $params) ? $params[$key] : $default;
    }

    /**
     * Access parsed body (POST / form) parameters.
     *
     * post()                         → DataContainer of all body params
     * post('key', $default)          → single value or $default
     * post(['key' => $default, ...]) → DataContainer of requested keys, defaults applied
     *
     * @param string|array|null $key
     * @param mixed $default
     * @return mixed
     */
    public function post($key = null, $default = null)
    {
        $body   = $this->request->getParsedBody();
        $params = is_array($body) ? $body : (is_object($body) ? (array) $body : []);

        if ($key === null) {
            return new DataContainer($params);
        }

        if (is_array($key)) {
            $result = [];
            foreach ($key as $k => $default_val) {
                $result[$k] = array_key_exists($k, $params) ? $params[$k] : $default_val;
            }
            return new DataContainer($result);
        }

        return array_key_exists($key, $params) ? $params[$key] : $default;
    }

    /**
     * Access uploaded files.
     *
     * files()                     → DataContainer of all UploadedFileInterface objects
     * files('key')                → UploadedFileInterface or null
     * files(['key' => null, ...]) → DataContainer of requested keys, defaults applied
     *
     * @param string|array|null $key
     * @return mixed
     */
    public function files($key = null)
    {
        $uploaded = $this->request->getUploadedFiles();

        if ($key === null) {
            return new DataContainer($uploaded);
        }

        if (is_array($key)) {
            $result = [];
            foreach ($key as $k => $default_val) {
                $result[$k] = array_key_exists($k, $uploaded) ? $uploaded[$k] : $default_val;
            }
            return new DataContainer($result);
        }

        return $uploaded[$key] ?? null;
    }

    /**
     * Parse and return the raw request body as JSON.
     * Returns the decoded value (array/scalar) or null if the body is empty or invalid.
     *
     * @return mixed
     */
    public function json()
    {
        $body = (string) $this->request->getBody();
        if ($body === '') {
            return null;
        }
        return json_decode($body, true);
    }

    /**
     * Get a PSR-7 request attribute set by middleware (e.g. 'lang' from LocaleMiddleware).
     *
     * @param mixed $default
     * @return mixed
     */
    public function attr(string $key, $default = null)
    {
        $value = $this->request->getAttribute($key);
        return $value !== null ? $value : $default;
    }

    /**
     * Get the first value of a named request header, case-insensitive.
     */
    public function header(string $name, string $default = ''): string
    {
        $values = $this->request->getHeader($name);
        return $values ? $values[0] : $default;
    }

    /**
     * Extract the Bearer token from the Authorization header, or null if absent.
     */
    public function bearer_token(): ?string
    {
        $auth = $this->header('Authorization');
        if (strpos($auth, 'Bearer ') === 0) {
            return substr($auth, 7);
        }
        return null;
    }

    /**
     * Get a cookie value by name, or $default if not present.
     *
     * @param mixed $default
     * @return mixed
     */
    public function cookie(string $name, $default = null)
    {
        $cookies = $this->request->getCookieParams();
        return array_key_exists($name, $cookies) ? $cookies[$name] : $default;
    }

    /**
     * Return the HTTP method in uppercase (GET, POST, PUT, …).
     */
    public function method(): string
    {
        return strtoupper($this->request->getMethod());
    }

    /**
     * True if the request Content-Type indicates a JSON payload.
     */
    public function is_json(): bool
    {
        return strpos($this->header('Content-Type'), 'application/json') !== false;
    }

    /**
     * True if the request was made via XMLHttpRequest (Ajax).
     */
    public function is_ajax(): bool
    {
        return $this->header('X-Requested-With') === 'XMLHttpRequest';
    }

    /**
     * Return the client IP address, or null if not available.
     */
    public function ip(): ?string
    {
        return $this->request->getServerParams()['REMOTE_ADDR'] ?? null;
    }

    /**
     * Return the URI path (without query string).
     */
    public function path(): string
    {
        return $this->request->getUri()->getPath();
    }
}
