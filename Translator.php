<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
// src/Libs/Italix/Mvc/Translator.php

declare(strict_types=1);

namespace Italix\Mvc;

use Italix\Contracts\Translator as TranslatorContract;

/**
 * The framework's original translator: dot keys, `:placeholder` substitution.
 *
 * It now satisfies `Italix\Contracts\Translator`, which is what lets an
 * application move to `Italix\I18n\Translator` **one site at a time** instead of
 * all at once: libraries type-hint the contract and stop caring which one they
 * were handed.
 *
 * ## Two things changed shape here, and neither breaks a caller
 *
 * The **slug half** moved to {@see LocalizedPaths}. Mapping URL segments and
 * looking up messages share a directory of language files and nothing else, and
 * keeping them on one object meant replacing the message half would drag routing
 * along. The methods remain, deprecated, delegating.
 *
 * `choice()` arrived because the contract asks for it. Here it is a **deliberate
 * approximation**: this class has no CLDR data, so it can only tell one from
 * not-one. That is right for English and Italian and wrong for Russian, Polish
 * and Arabic — which is the honest reason to prefer `Italix\I18n`, and is stated
 * on the method rather than hidden.
 *
 * @see \Italix\I18n\Translator for ICU messages and real plural categories
 */
class Translator implements TranslatorContract
{
    private string $locale;
    private string $canonical_lang;
    private array  $supported_langs;
    private string $lang_dir;

    /** @var array<string, string> group name => directory of a library's catalogue */
    private array $groups = [];

    /** Lazy per locale: [ locale => ['messages' => [...]] ] */
    private array $loaded = [];

    private LocalizedPaths $paths;

    /**
     * Where sentences come from, when somebody else supplies them.
     *
     * Null means the built-in lookup below — the `:name` substitution and the
     * lazily-required message files. That path still works and still ships,
     * because an application using it should not have to change to upgrade.
     *
     * Set, it means this class has stopped being a translator and become what
     * it is better at: the locale of the current request, the languages this
     * site answers in, and the slug routing. Message lookup is delegated, so
     * there is one implementation of it rather than two.
     */
    private ?TranslatorContract $messages = null;

    /**
     * @param array<string, string>    $groups   Catalogues shipped by libraries:
     *                                           group name => directory laid out
     *                                           as `{dir}/{locale}/{group}.php`
     *                                           Ignored when $messages is given.
     * @param TranslatorContract|null  $messages Delegate for lookup and
     *                                           interpolation — normally
     *                                           `Italix\I18n\Translator`, which
     *                                           has CLDR plurals and ICU
     *                                           messages that this class only
     *                                           approximates.
     */
    public function __construct(
        string $canonical_lang,
        array $supported_langs,
        string $lang_dir,
        array $groups = [],
        ?TranslatorContract $messages = null
    ) {
        $this->canonical_lang  = $canonical_lang;
        $this->supported_langs = $supported_langs;
        $this->lang_dir        = rtrim($lang_dir, '/') . '/';
        $this->groups          = $groups;
        $this->locale          = $canonical_lang;
        $this->paths           = new LocalizedPaths($canonical_lang, $lang_dir);
        $this->messages        = $messages === null ? null : $messages->in($canonical_lang);
    }

    /**
     * The delegate, for callers that need what the contract does not carry.
     *
     * Returns null on the built-in path, which is the honest answer: there is
     * no separate object to hand back.
     */
    public function messages(): ?TranslatorContract
    {
        return $this->messages;
    }

    // -------------------------------------------------------------------------
    // Locale management
    // -------------------------------------------------------------------------

    /**
     * Point this translator at a locale, mutating it.
     *
     * **Call this on every request, not only on the ones that name a language.**
     * A translator is normally a container singleton, and a singleton keeps the
     * last locale anybody set. Under PHP-FPM that is invisible, because the
     * process ends with the request. On a resident worker — RoadRunner,
     * FrankenPHP, Octane — a request that skips this call is rendered in the
     * language of the request before it.
     *
     * Middleware that only sets the locale inside `if (path names a language)`
     * has this bug and passes every test, because a test client sends one
     * request at a time from a fresh process.
     *
     * Where the locale is needed for one value rather than for the request —
     * an e-mail to a recipient who reads another language — use {@see in()},
     * which returns a copy and leaves this object alone.
     */
    public function set_locale(string $locale): void
    {
        $this->locale = $locale;

        if ($this->messages !== null) {
            // The delegate is immutable per locale by design, so pointing this
            // object at a language means replacing the one it asks. Doing it
            // here rather than at every get() is what keeps `in()` honest: a
            // copy taken for an e-mail must not move when the page's locale is
            // set again afterwards.
            $this->messages = $this->messages->in($locale);

            return;
        }

        $this->load($locale);
    }

    public function locale(): string
    {
        return $this->locale;
    }

    /** The contract's spelling of {@see locale()}. Both are the same value. */
    public function locale_code(): string
    {
        return $this->locale;
    }

    public function canonical_lang(): string
    {
        return $this->canonical_lang;
    }

    public function supported_langs(): array
    {
        return $this->supported_langs;
    }

    public function supports(string $locale): bool
    {
        return in_array($locale, $this->supported_langs, true);
    }

    /**
     * A copy answering in another locale, leaving this one untouched.
     *
     * The property that matters: an e-mail composed in the recipient's language
     * must not change the language the surrounding page is being rendered in.
     */
    public function in(string $locale_c): TranslatorContract
    {
        $copy         = clone $this;
        $copy->locale = $locale_c;

        if ($this->messages !== null) {
            $copy->messages = $this->messages->in($locale_c);

            return $copy;
        }

        $copy->load($locale_c);

        return $copy;
    }

    // -------------------------------------------------------------------------
    // UI string translation
    // -------------------------------------------------------------------------

    /**
     * Whether the key exists in this locale or in the canonical fallback.
     */
    public function has(string $key_c): bool
    {
        if ($this->messages !== null) {
            // No second call against the canonical language: a delegate is
            // built with its own fallback, and asking twice here would report
            // a key as present that the delegate would refuse.
            return $this->messages->has($key_c);
        }

        return $this->dot_get($this->data($this->locale)['messages'], $key_c) !== null
            || $this->dot_get($this->data($this->canonical_lang)['messages'], $key_c) !== null;
    }

    /**
     * Translates a dot-notation key for the current locale.
     *
     * Falls back to the canonical language, then to the key itself.
     *
     *     $t->get('nav.about')                      // 'Chi siamo'
     *     $t->get('flash.welcome', ['name' => 'Jo']) // 'Benvenuto, Jo'
     *
     * ## Both placeholder spellings are accepted
     *
     * `:name` is this class's own; `{name}` is ICU's, which
     * `Italix\I18n\Translator` speaks. Accepting both is what lets a **catalogue
     * migrate ahead of the engine**: without it, converting a message file would
     * mean converting the translator in the same commit, and any message
     * converted early would print its placeholder raw on a live page.
     *
     * Substitution here is literal in both cases — no plural selection, no
     * nesting. A catalogue that has moved to ICU constructs needs the ICU
     * translator; this only carries simple named values across the gap.
     */
    public function get(string $key, array $replacements = []): string
    {
        if ($this->messages !== null) {
            return $this->messages->get($key, $replacements);
        }

        $value = $this->dot_get($this->data($this->locale)['messages'], $key)
              ?? $this->dot_get($this->data($this->canonical_lang)['messages'], $key)
              ?? $key;

        foreach ($replacements as $k => $v) {
            $value = str_replace([':' . $k, '{' . $k . '}'], (string) $v, $value);
        }

        return $value;
    }

    /**
     * A message chosen by count — **singular or not, and nothing finer**.
     *
     * The catalogue holds the two forms separated by a pipe:
     *
     *     'messages.count' => ':n messaggio|:n messaggi'
     *
     * This is not CLDR. Plural categories are a property of a language: Russian
     * has four, Arabic six, Japanese one, and no amount of `$n === 1` gets that
     * right. This class carries no such data and does not pretend to — it is
     * correct for English and Italian and wrong beyond them.
     *
     * `Italix\I18n\Translator` answers the same contract with ICU behind it and
     * is the right implementation for anything else.
     */
    public function choice(string $key_c, int $count_n, array $params = []): string
    {
        if ($this->messages !== null) {
            // The whole point of delegating: the caveat above stops applying,
            // because the delegate carries the CLDR categories this class does
            // not have.
            return $this->messages->choice($key_c, $count_n, $params);
        }

        $message = $this->get($key_c, $params + ['n' => $count_n]);
        $forms   = explode('|', $message);

        if (count($forms) < 2) {
            return $message;
        }

        return $count_n === 1 ? $forms[0] : $forms[1];
    }

    // -------------------------------------------------------------------------
    // Slug translation — moved to LocalizedPaths
    // -------------------------------------------------------------------------

    /** The path mapper this translator was built with. */
    /**
     * A whole branch of the catalogue, for handing to something that is not PHP.
     *
     * `get()` answers one key, which is right for a template and wrong for a
     * client-side mirror that needs the sentences for every code a rule can
     * return. Without this the page had to carry a hand-written list of rules
     * and error codes — and a hand-written list is a list that falls behind the
     * moment a rule gains a code.
     *
     * Returns `[]` for a key that is missing or is not a branch, rather than
     * throwing: a page that renders no messages is better than a page that does
     * not render.
     *
     * @return array<string, mixed>
     */
    public function group(string $key_c): array
    {
        if ($this->messages !== null) {
            // `group()` is not on the contract, and putting it there for one
            // caller would make every implementation carry it. Asked by name
            // instead: a delegate that has it answers, one that does not gets
            // the same empty array a missing key already returns — which the
            // paragraph above says is a page that renders, not a 500.
            return method_exists($this->messages, 'group')
                ? (array) $this->messages->group($key_c)
                : [];
        }

        $node = $this->data($this->locale)['messages'] ?? [];

        foreach (explode('.', $key_c) as $segment_c) {
            if (!is_array($node) || !array_key_exists($segment_c, $node)) {
                return [];
            }

            $node = $node[$segment_c];
        }

        return is_array($node) ? $node : [];
    }

    public function paths(): LocalizedPaths
    {
        return $this->paths;
    }

    /**
     * @deprecated 1.1.0 Use `LocalizedPaths::slug($path, $locale)`. Removed in 2.0.0.
     *
     * Find the call sites:
     *
     *     grep -rn '\->slug(' --include='*.php' src/
     */
    public function slug(string $canonical_path): string
    {
        return $this->paths->slug($canonical_path, $this->locale);
    }

    /**
     * @deprecated 1.1.0 Use `LocalizedPaths::resolve($path, $locale)`. Removed in 2.0.0.
     *
     *     grep -rn '\->resolve_slug(' --include='*.php' src/
     */
    public function resolve_slug(string $incoming_path): string
    {
        return $this->paths->resolve($incoming_path, $this->locale);
    }

    /**
     * @deprecated 1.1.0 Use `LocalizedPaths::url($path, $locale)`. Removed in 2.0.0.
     *
     *     grep -rn '\->url(' --include='*.php' src/
     */
    public function url(string $canonical_path, ?string $locale = null): string
    {
        return $this->paths->url($canonical_path, $locale ?? $this->locale);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function dot_get(array $data, string $key): ?string
    {
        $parts = explode('.', $key);
        $node  = $data;

        foreach ($parts as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return null;
            }
            $node = $node[$part];
        }

        return is_string($node) ? $node : null;
    }

    /**
     * The application's messages, over any catalogue its libraries ship.
     *
     * A library that defines error codes can ship the sentences for them —
     * `italix/rules` does, in `lang/{locale}/rules.php`. Until this existed
     * there was no way to *use* those files, so applications copied them, and a
     * copy is a thing that falls behind: this project's own catalogue had
     * silently lost `luhn` and `uuid`, which meant an Italian page showing an
     * English fallback.
     *
     * The application still wins on every key it defines. Merging beneath is
     * what makes the shipped sentences a floor rather than a straitjacket.
     */
    private function load(string $locale): void
    {
        if (isset($this->loaded[$locale])) {
            return;
        }

        $messages_file = $this->lang_dir . $locale . '/messages.php';
        $messages      = is_file($messages_file) ? require $messages_file : [];

        foreach ($this->groups as $group_c => $dir) {
            $group_file = rtrim($dir, '/') . '/' . $locale . '/' . $group_c . '.php';

            if (!is_file($group_file)) {
                continue;
            }

            $messages[$group_c] = array_replace_recursive(
                (array) require $group_file,
                (array) ($messages[$group_c] ?? [])
            );
        }

        $this->loaded[$locale] = ['messages' => $messages];
    }

    private function data(string $locale): array
    {
        $this->load($locale);

        return $this->loaded[$locale];
    }
}
