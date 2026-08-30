# Changelog — italix/mvc

Format: [Keep a Changelog](https://keepachangelog.com/). Versioning policy: `VERSIONING.md` at the
project root.

## [2.0.0] — 2026-08-28

### Changed — BREAKING

`_c` on function/method names is retired in favor of spelling out what the value actually is —
see `src/Libs/Italix/CONVENTIONS.md`, "`_c` is for variables... only." `_c` stays on variables,
parameters and properties; only the method name changed, no behavior:

- `Translator::locale_c()` → `locale_code()` — implements `Italix\Contracts\Translator`, whose
  own `locale_c()` renamed the same way. Requires `italix/contracts` `^2.0` now (was `^1.0`).

(`LocalizedPaths::slug()`, `Translator::slug()`, `RequestInput::ip()`, `RequestInput::path()` are
unrelated pre-existing methods, never suffixed `_c`, never touched by this or any other rename.)

## [1.7.0] — 2026-08-17

### Added

- **`Responses`, a PSR-17 seam.** Every response this framework builds now comes from a
  `Psr\Http\Message\ResponseFactoryInterface`, and an application can swap it:

  ```php
  Responses::use_factory(new \Nyholm\Psr7\Factory\Psr17Factory());
  ```

  Before this, `new GuzzleHttp\Psr7\Response(...)` appeared in five files across four classes, so
  the framework quietly required Guzzle — not the interfaces, the implementation. An application
  already shipping `nyholm/psr7`, which is a fraction of the size and is sometimes what a host
  mandates, carried two PSR-7 implementations to use one framework.

  Guzzle stays the default via `GuzzleResponseFactory`, which is now the **only** file in the
  package that names a PSR-7 implementation. Nothing changes for existing callers.

### Fixed

- **`psr/http-message` and `psr/http-factory` were used and not required.** Both arrived
  transitively — through `guzzlehttp/psr7` and `psr/http-server-middleware` — so nothing ever broke
  here, and nothing would have broken until the day a consumer's resolution dropped one of them.
  `BaseController` has type-hinted `ResponseInterface` since the beginning.

  This is the same defect as the one in `italix/i18n` and `italix/rules`, in its third form: an
  interface-only package is invisible when something else already installed it.

## [1.6.3] — 2026-08-17

### Fixed

- **`php: >=7.4` was not true**, because `ControllerHandler` and `MiddlewareHandler` used constructor
  property promotion (PHP 8.0). Both are under fifteen lines; the constructors are written out now
  rather than raising the floor of the whole framework for them.

## [1.6.2] — 2026-08-17

### Documentation

- **`italix/forms` is now listed in `suggest`.** `View::form()` returns an `Italix\Forms\FormHtml`
  and already guards the call with `class_exists()` plus a message naming the package — but the
  manifest did not mention it, so the one place this library is extensible was invisible to anyone
  reading the package rather than the source.

## [1.6.1] — 2026-08-14

### Documentation

- **`Translator::set_locale()` now states that it must be called on every request**, not only on the
  ones whose path names a language.

  A translator is normally a container singleton, and a singleton keeps the last locale anybody set.
  Under PHP-FPM that is invisible, because the process ends with the request. On a resident worker —
  RoadRunner, FrankenPHP, Octane — a request that skips the call is rendered in the language of the
  request before it: a 404, a health check or an asset path served in whatever language the previous
  visitor happened to be reading.

  Middleware that sets the locale only inside `if (the path names a language)` has this bug and
  passes every test, because a test client sends one request at a time from a fresh process. The
  docblock says so, and points at `in()` — which returns a copy — as the primitive for translating
  one value without moving the request's own locale.

  No code changed. The defect this describes was in the *application's* `LocaleMiddleware`, fixed the
  same day with a regression suite that reproduces the resident-worker condition on purpose: one
  engine, one container, several requests.


### Legal

- **Licensed under MPL-2.0**, applied 2026-08-13: the `license` field in `composer.json`, a `LICENSE`
  file, and the Exhibit A notice in every source file — MPL §1.4 defines "Covered Software" per file,
  so the per-file header is what makes the licence apply rather than decoration.

  This is a **first declaration, not a relicensing.** The package carried no licence at all before,
  which in most jurisdictions means all rights reserved: nothing had been granted, so nothing is
  taken away and no consumer's position gets worse. That is why it is recorded here rather than
  treated as a breaking change — unlike `italix/orm`, which went Apache-2.0 → MPL-2.0 and took a
  MAJOR because that direction does narrow what a consumer already had.

## [1.6.0] — 2026-08

### Added

- **`Translator` merges catalogues shipped by libraries.** A new optional `groups` parameter maps a
  group name to a directory laid out as `{dir}/{locale}/{group}.php`; each is merged **beneath** the
  application's own `messages.php`, so anything the application defines still wins.

  ```php
  new Translator(..., groups: ['rules' => __DIR__ . '/../Libs/Italix/Rules/lang']);
  ```

  `italix/rules` has shipped `lang/{en,it}/rules.php` since 2.1.0 on the principle that whoever
  defines the error codes can define the sentences for them — but there was no way to *use* those
  files, so applications copied them. A copy is a thing that falls behind: this project's own had
  silently lost `luhn` and `uuid`, which meant an Italian page rendering an English fallback.

- **`Translator::group()`** — a whole branch of the catalogue as an array.

  `get()` answers one key, which is right for a template and wrong for handing the sentences to
  something that is not PHP. The browser mirror needs every code a rule can return, and without this
  the page carried a hand-written list of rules and error codes — which had already fallen behind
  for `bic` and `barcode`. The branch itself is the list.

  Returns `[]` for a missing key rather than throwing: a page that renders no messages beats a page
  that does not render.

## [1.5.0] — 2026-08

The translator was doing two unrelated jobs. They are separate now, and it answers a shared contract
— so an application can move to `Italix\I18n` gradually instead of all at once.

### Added

- **`Translator` implements `Italix\Contracts\Translator`.** It gained `locale_c()` (an alias of
  `locale()`), `has()`, `in()` and `choice()`. Nothing existing changed.

  `in($locale)` returns a **copy**: an e-mail composed in the recipient's language must not change
  the language the surrounding page is being rendered in.

  **`get()` now accepts both placeholder spellings** — `:name` and `{name}`. This is what lets a
  **catalogue migrate ahead of the engine**: without it, converting a message file to ICU syntax
  would mean converting the translator in the same commit, and any message converted early would
  print its placeholder raw on a live page. Verified by mutation: with the old single-spelling
  substitution, a converted message renders as `Verifica i tuoi dati — {reason}`.

  Substitution stays literal in both cases — no plural selection, no nesting. A catalogue that has
  moved to real ICU constructs needs the ICU translator; this only carries simple named values
  across the gap.

  `choice()` is a **deliberate approximation** and says so on the method. The catalogue holds two
  forms separated by a pipe — `':n pratica|:n pratiche'` — and this class can only tell one from
  not-one. Right for English and Italian, wrong for Russian, Polish and Arabic. That is the honest
  reason to prefer `Italix\I18n\Translator`, which answers the same contract with ICU behind it.

- **`LocalizedPaths`** — canonical URL paths in, localised ones out, and back again.

### Deprecated

- **`Translator::slug()`, `resolve_slug()`, `url()`** — use `LocalizedPaths` instead. Removed in
  **2.0.0**. They now delegate, so behaviour is identical; `Translator::paths()` hands out the
  object to migrate to.

  Mapping URL segments and looking up messages share a directory of language files and nothing else.
  Keeping them on one object meant that replacing the message half would drag routing along with it.

  Measured on the application that grew this framework, which is why the split is cheap: **339** calls
  to `get()` against **3** to the whole slug half.

  Find the call sites:

  ```
  grep -rn '\->slug(\|\->resolve_slug(\|\->url(' --include='*.php' src/
  ```

  Careful with the last one: `Italix\Routing`'s URL builder has a `to()`, not a `url()`, but an
  application may well have its own.

### Changed

- `Translator` no longer reads `slugs.php`. Only `LocalizedPaths` does. No configuration changes —
  the file stays where it is and is read by the object that needs it.

## [1.4.0] — 2026-08

### Added

- **`routes.file`** and **`cache.dir`** configuration keys. Both default to what the engine assumed
  before — `{base_dir}/src/sites/{host}/routes.php` and `{base_dir}/data/{host}` — so nothing
  changes for an application that follows the convention.

  The assumption was the problem. An application that kept its route definitions anywhere else
  failed `is_file()` silently: the mtime comparison never fired, the compiled dispatcher was never
  invalidated, and a stale route table was served indefinitely. A cache that is wrong quietly is
  worse than one that is wrong loudly.

  Found by `ix libs:check` while auditing the libraries for coupling to this application. It was a
  false positive for *that* question — the path is built from `$host`, not from a site name — and a
  real defect for a different one.

## [1.3.0] — 2026-08

### Added

- **`ViewRenderer::share(array $data)`** and **`ViewRenderer::shared()`** — values made available to
  every template, layout, partial and component rendered from that renderer.

  `View` carries the same data, so it reaches `render()`, `partial()` and `component()` and not only
  the page file. `component()` sees it too: its documented isolation is from *the parent's*
  variables, and shared data is framework ambient — a language switcher unable to reach `$url` would
  have to be handed it by every caller, which is the coupling the isolation exists to prevent.

  Precedence, from weakest: shared → structural (`$view`, `$t`) → per-call `$data`. A page can
  therefore override a shared value for itself, and nothing can accidentally replace `$view`.

  The motivating case is `italix/routing`: 25 templates needed a URL generator, and passing it from
  every controller means the one that forgets fails at render time in whichever branch nobody
  clicked.

  **Use sparingly.** A shared variable is a global. Its only justification is that the alternative
  is 25 identical edits.

- `View::__construct()` takes an optional `array $shared` as its fifth parameter — appended, so
  existing constructions are unaffected.

## [1.2.0] — 2026-08

### Added

- **`ServiceRegistry` implements PSR-11 `Psr\Container\ContainerInterface`** — `has()` alongside the
  existing `get()`. `psr/container` is an interface-only package, so the interop costs nothing at
  runtime and nothing in the dependency count (house rule 12).

  The point is that `Italix\Console` can resolve a command's dependencies from this registry without
  depending on `italix/mvc` — house rule 13 keeps new libraries leaves, and a PSR interface is the
  seam that allows it.

- `ServiceRegistry::keys()` — the registered keys, for diagnostics.

### Changed

- **Asking for an unregistered key now throws `ServiceNotFound`** (which implements PSR-11's
  `NotFoundExceptionInterface`) instead of emitting an "undefined array key" warning and returning
  null.

  Scored as a MINOR rather than a MAJOR, and the reasoning is recorded here rather than left
  implicit: `VERSIONING.md` calls a behaviour change under an existing signature a MAJOR, but the
  previous behaviour was already a fatal path — a warning followed by a `TypeError` in whatever
  class received the null, naming neither the key nor the caller. Nothing correct could depend on
  it. The new exception names the key at the point of the lookup.

### Changed — `Engine::registry()`

- No longer builds the dispatcher. It was added in 1.1.0 calling `boot()`, which built both; running
  `ix version` would therefore write a route cache file as a side effect. `boot()` still builds
  both, `registry()` now builds only the container.

## [1.1.0] — 2026-08

### Added

- **`Engine::boot()`** — builds the container and the dispatcher without handling anything.
  Idempotent.
- **`Engine::registry()`** — the `ServiceRegistry`, booting first if necessary.
- **`Engine::pipeline()`** — the complete request handler: global middleware wrapping the router.

  Before this, `Engine` implemented `RequestHandlerInterface` but `handle()` was unusable from
  outside `run()`: the registry and the dispatcher were built inside `run()`, so calling `handle()`
  directly dereferenced null. `run()` also emits, which a caller that wants the response cannot
  undo.

  These three make the engine drivable in-process — by `italix/testing`, and later by
  `Italix\Console`. `pipeline()` is the one to use: it exercises the same stack a real request does,
  locale and CSRF and authentication included, rather than a reduced one.

### Changed

- `run()` is now `emit_response($this->pipeline()->handle($request))`. Same behaviour, same error
  handling, one path instead of two.

## [1.0.0] — baseline

Versioning starts here. This entry records the state of the library at the time the policy was
adopted, not a release.

### Contents

- **`Engine`** — implements PSR-15 `RequestHandlerInterface`. `run()` boots the container and the
  dispatcher, wraps everything in the global middleware pipeline, and emits. Typed exception handler
  map; chunked, output-buffer-aware emitter.
- **`Pipeline`**, **`BaseMiddleware`**, **`MiddlewareHandler`** — PSR-15 middleware, global and
  per-route.
- **`ServiceRegistry`** — services declared in `conf.php`, pulled by `depends_on()` on controllers
  and middleware.
- **`BaseController`**, **`BaseViewController`**, **`BaseInertiaAction`**, **`ControllerHandler`**.
- **`View`** / **`ViewRenderer`** — block-and-layout templates, partials, theme fallback.
- **`Session`**, **`Translator`**, **`RequestInput`**.

### Known compatibility notes

- **Nothing in this library escapes anything.** `ViewRenderer::render()` returns a `string` and
  templates encode explicitly with `italix/encode`. That is a documented position, not an oversight.
- **`Session::csrf_field(): string` returns markup typed as a string.** It is an allow-list entry in
  `.encode-lint` for exactly that reason. Narrowing it to `Italix\Encode\Html` is a planned MAJOR;
  see `VERSIONING.md`, house rule 15.
- **Route cache invalidation uses `filemtime`.** A deployment that preserves timestamps can serve a
  stale dispatcher. The fix — invalidate on a content hash — is a PATCH and is listed in
  FRAMEWORK-FUTURE.md §4.5.

### Planned, all MINOR

- `Engine::boot()` and `Engine::pipeline()` — extracted from `run()` so a request can be handled
  in-process without emitting. Required by `italix/testing`; additive, no signature changes.
- `problem()` on `BaseController` — `application/problem+json` responses.
- A `Probe` implementation for the dev toolbar.
