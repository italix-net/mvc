<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
// src/Libs/Italix/Mvc/LocalizedPaths.php

declare(strict_types=1);

namespace Italix\Mvc;

/**
 * Canonical URL paths in, localised ones out — and back again.
 *
 *     /about-us   ->  /chi-siamo        (it)
 *     /chi-siamo  ->  /about-us         (the middleware, before dispatch)
 *
 * ## Why this is not the translator's job
 *
 * It used to live on `Italix\Mvc\Translator`, which therefore did two unrelated
 * things: looking up messages, and mapping URL segments. They share a directory
 * of language files and nothing else. A page asks the first constantly and the
 * second almost never — measured on the application that grew this framework:
 * 339 calls to `get()` against 3 to the whole slug half.
 *
 * Keeping them together meant that adopting a better message translator would
 * have dragged routing along with it. They are separate now so that either can
 * be replaced on its own.
 *
 * Reads `{lang_dir}/{locale}/slugs.php`:
 *
 *     return [
 *         'paths'    => ['/about-us' => '/chi-siamo'],   // whole paths, exact
 *         'segments' => ['news' => 'notizie'],           // one segment at a time
 *     ];
 */
final class LocalizedPaths
{
    private string $canonical_lang;
    private string $lang_dir;

    /** @var array<string, array{paths: array, paths_rev: array, segments: array, segments_rev: array}> */
    private array $loaded = [];

    public function __construct(string $canonical_lang, string $lang_dir)
    {
        $this->canonical_lang = $canonical_lang;
        $this->lang_dir       = rtrim($lang_dir, '/') . '/';
    }

    public function canonical_lang(): string
    {
        return $this->canonical_lang;
    }

    /**
     * Canonical path -> the path this locale writes.
     *
     * The canonical language is a no-op by definition: its paths are already
     * canonical, and translating them would need a map to itself.
     */
    public function slug(string $canonical_path, string $locale): string
    {
        if ($locale === $this->canonical_lang) {
            return $canonical_path;
        }

        $data = $this->data($locale);

        // Whole-path first: the same segment can translate differently depending
        // on where it sits, and only an exact match knows that.
        if (isset($data['paths'][$canonical_path])) {
            return $data['paths'][$canonical_path];
        }

        return $this->map_segments($canonical_path, $data['segments']);
    }

    /**
     * Localised path -> canonical path, for the middleware to rewrite an
     * incoming URI before routing sees it.
     *
     * An unknown path passes through unchanged: the router will produce the 404,
     * and it can say more about it than this can.
     */
    public function resolve(string $incoming_path, string $locale): string
    {
        if ($locale === $this->canonical_lang) {
            return $incoming_path;
        }

        $data = $this->data($locale);

        if (isset($data['paths_rev'][$incoming_path])) {
            return $data['paths_rev'][$incoming_path];
        }

        return $this->map_segments($incoming_path, $data['segments_rev']);
    }

    /** The full localised URL, language prefix included: `/it/chi-siamo`. */
    public function url(string $canonical_path, string $locale): string
    {
        return '/' . $locale . $this->slug($canonical_path, $locale);
    }

    private function map_segments(string $path, array $map): string
    {
        $segments   = explode('/', ltrim($path, '/'));
        $translated = array_map(static fn ($seg) => $map[$seg] ?? $seg, $segments);

        return '/' . implode('/', $translated);
    }

    /** @return array{paths: array, paths_rev: array, segments: array, segments_rev: array} */
    private function data(string $locale): array
    {
        if (!isset($this->loaded[$locale])) {
            $file  = $this->lang_dir . $locale . '/slugs.php';
            $slugs = is_file($file) ? require $file : [];

            $paths    = $slugs['paths']    ?? [];
            $segments = $slugs['segments'] ?? [];

            $this->loaded[$locale] = [
                'paths'        => $paths,
                'paths_rev'    => array_flip($paths),
                'segments'     => $segments,
                'segments_rev' => array_flip($segments),
            ];
        }

        return $this->loaded[$locale];
    }
}
