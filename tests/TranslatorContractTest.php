<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Mvc — the translator seam, and the slug half that moved
 *
 * Until `Italix\Contracts\Translator` existed there were two translators in the
 * framework that could not see each other, so a library had to pick a side and
 * an application could not move from one to the other gradually.
 *
 * What this pins down is the property that makes a gradual move possible: **both
 * implementations satisfy the same contract**, so a caller type-hinted against
 * it works with either. Not that they behave identically — they do not, and the
 * differences are asserted here rather than glossed over.
 *
 * Run: php src/Libs/Italix/Mvc/tests/TranslatorContractTest.php
 */

declare(strict_types=1);

(static function (): void {
    foreach ([
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/../../../../../vendor/autoload.php',
        __DIR__ . '/../../../../vendor/autoload.php',
        __DIR__ . '/../../../autoload.php',
    ] as $autoload) {
        if (is_file($autoload)) {
            require_once $autoload;

            return;
        }
    }

    fwrite(STDERR, "Could not find an autoloader. Run composer install.\n");
    exit(2);
})();

use Italix\Contracts\Translator as TranslatorContract;
use Italix\I18n\Catalog;
use Italix\I18n\Translator as IcuTranslator;
use Italix\Mvc\LocalizedPaths;
use Italix\Mvc\Translator as MvcTranslator;

use function Italix\Testing\{suite, section, test, summary};

suite('Italix Mvc — translator contract and localized paths');

// A throwaway language directory, so the test depends on no application.
$work = sys_get_temp_dir() . '/ix-translator-' . bin2hex(random_bytes(4));

@mkdir($work . '/en', 0775, true);
@mkdir($work . '/it', 0775, true);

file_put_contents($work . '/en/messages.php', '<?php return ' . var_export([
    'nav'   => ['about' => 'About us'],
    'hello' => 'Hello, :name',
    'messages' => ['count' => ':n message|:n messages'],
], true) . ';');

file_put_contents($work . '/it/messages.php', '<?php return ' . var_export([
    'nav'   => ['about' => 'Chi siamo'],
    'hello' => 'Ciao, :name',
    'messages' => ['count' => ':n messaggio|:n messaggi'],
], true) . ';');

file_put_contents($work . '/it/slugs.php', '<?php return ' . var_export([
    'paths'    => ['/about-us' => '/chi-siamo'],
    'segments' => ['news' => 'notizie', 'archive' => 'archivio'],
], true) . ';');

try {

// -----------------------------------------------------------------------------
section('both translators answer the same contract');

$mvc = new MvcTranslator('en', ['en', 'it'], $work);
$icu = new IcuTranslator([
    new Catalog('it', ['nav' => ['about' => 'Chi siamo'], 'n' => '{n, plural, one{# messaggio} other{# messaggi}}']),
    new Catalog('en', ['nav' => ['about' => 'About us']]),
], 'it', 'en');

test('Italix\Mvc\Translator satisfies the contract', $mvc instanceof TranslatorContract);
test('Italix\I18n\Translator satisfies it too', $icu instanceof TranslatorContract);

// The point of the seam: a function that knows neither implementation.
$greet = static function (TranslatorContract $t): string {
    return $t->get('nav.about');
};

test('a caller typed against the contract works with the Mvc one', $greet($mvc) === 'About us');
test('…and with the ICU one', $greet($icu) === 'Chi siamo');

test('both spell the locale the same way', $mvc->locale_code() === 'en' && $icu->locale_code() === 'it');
test('…and both still answer the old spelling', $mvc->locale() === 'en' && $icu->locale() === 'it');

test('has() finds a key', $mvc->has('nav.about'));
test('…and says so when there is none', !$mvc->has('nav.nope'));

// -----------------------------------------------------------------------------
section('both placeholder spellings, so a catalogue can migrate before the engine');

// Without this, converting a message file to ICU syntax would mean converting
// the translator in the same commit — and any message converted early would
// print its placeholder raw on a live page.
//
// Note this class reads `messages.php` and nothing else: the one-file-per-group
// idea belongs to Italix\I18n's loader, not here.
file_put_contents($work . '/en/messages.php', '<?php return ' . var_export([
    'nav'   => ['about' => 'About us'],
    'hello' => 'Hello, :name',
    'messages' => ['count' => ':n message|:n messages'],
    'mixed' => [
        'old'  => 'Hello, :name',
        'new'  => 'Hello, {name}',
        'both' => ':greeting, {name}',
    ],
], true) . ';');

$mixed = new MvcTranslator('en', ['en'], $work);

test('the old spelling still substitutes', $mixed->get('mixed.old', ['name' => 'Anna']) === 'Hello, Anna');
test('the ICU spelling substitutes too', $mixed->get('mixed.new', ['name' => 'Anna']) === 'Hello, Anna');
test('a half-converted message works during the transition',
    $mixed->get('mixed.both', ['greeting' => 'Ciao', 'name' => 'Anna']) === 'Ciao, Anna');
test('a placeholder nobody supplied is left alone, not emptied',
    $mixed->get('mixed.new') === 'Hello, {name}');

// -----------------------------------------------------------------------------
section('in() copies rather than moves — the two-languages-in-one-request case');

$mvc->set_locale('it');

$english = $mvc->in('en');

test('the copy answers in the new locale', $english->get('nav.about') === 'About us');
test('THE ORIGINAL IS UNTOUCHED', $mvc->get('nav.about') === 'Chi siamo',
    'an e-mail in the recipient’s language must not change the page being rendered');
test('the copy is a different object', $english !== $mvc);

// -----------------------------------------------------------------------------
section('choice() exists on both, and is honestly weaker on one');

test('the Mvc translator picks the singular', $mvc->choice('messages.count', 1) === '1 messaggio');
test('…and the plural', $mvc->choice('messages.count', 3) === '3 messaggi');
test('…and zero is not one', $mvc->choice('messages.count', 0) === '0 messaggi');

test('a message with no pipe comes back whole', $mvc->choice('nav.about', 2) === 'Chi siamo');

// The reason to prefer Italix\I18n, stated as a test rather than as a comment:
// Russian needs three forms and the pipe carries two.
test('the ICU translator has real plural categories',
    $icu->choice('n', 1) === '1 messaggio' && $icu->choice('n', 5) === '5 messaggi');

// -----------------------------------------------------------------------------
section('the slug half moved, and the old spelling still works');

$paths = new LocalizedPaths('en', $work);

test('a whole-path match wins', $paths->slug('/about-us', 'it') === '/chi-siamo');
test('segments are mapped one by one otherwise', $paths->slug('/news/archive', 'it') === '/notizie/archivio');
test('the canonical language is a no-op', $paths->slug('/about-us', 'en') === '/about-us');
test('an unknown path passes through', $paths->slug('/whatever', 'it') === '/whatever');

test('resolve goes the other way', $paths->resolve('/chi-siamo', 'it') === '/about-us');
test('…segment by segment as well', $paths->resolve('/notizie/archivio', 'it') === '/news/archive');
test('…and passes the unknown through, for the router to 404',
    $paths->resolve('/mai-visto', 'it') === '/mai-visto');

test('url() prefixes the language', $paths->url('/about-us', 'it') === '/it/chi-siamo');
test('…for the canonical one too', $paths->url('/about-us', 'en') === '/en/about-us');

// The deprecated methods must keep answering exactly as before, or the
// deprecation window is a break with a nicer name.
test('the deprecated slug() still agrees', $mvc->slug('/about-us') === '/chi-siamo');
test('the deprecated resolve_slug() still agrees', $mvc->resolve_slug('/chi-siamo') === '/about-us');
test('the deprecated url() still agrees', $mvc->url('/about-us') === '/it/chi-siamo');
test('…including with an explicit locale', $mvc->url('/about-us', 'en') === '/en/about-us');
test('paths() hands out the object to migrate to', $mvc->paths() instanceof LocalizedPaths);

// -----------------------------------------------------------------------------
section('the messages half no longer reads the slugs file');

// It used to load both into one structure. A translator that stops parsing
// slugs.php is the visible half of the split.
test('a missing slugs.php is not a problem for messages',
    (new MvcTranslator('en', ['en'], $work))->get('nav.about') === 'About us');

} finally {
    foreach (['/en/messages.php', '/it/messages.php', '/it/slugs.php'] as $file) {
        @unlink($work . $file);
    }

    @rmdir($work . '/en');
    @rmdir($work . '/it');
    @rmdir($work);
}

test('the temporary directory was removed', !is_dir($work));

// -----------------------------------------------------------------------------
section('catalogues shipped by libraries, merged beneath the application\'s own');

// The defect this exists for is a copy that fell behind. `italix/rules` has
// shipped its own sentences since 2.1.0 on the principle that whoever defines
// the error codes defines the sentences — but there was no way to *use* those
// files, so this application copied forty of them, and the copy silently lost
// two. An Italian page rendered an English fallback for months.
$root = sys_get_temp_dir() . '/ix-groups-' . getmypid();

@mkdir($root . '/app/it', 0700, true);
@mkdir($root . '/lib/it', 0700, true);

file_put_contents($root . '/lib/it/rules.php', '<?php return ' . var_export([
    'iban' => ['checksum' => 'DALLA LIBRERIA: checksum'],
    'luhn' => ['checksum' => 'DALLA LIBRERIA: luhn'],
], true) . ';');

file_put_contents($root . '/app/it/messages.php', '<?php return ' . var_export([
    'hello' => 'ciao',
    'rules' => ['iban' => ['checksum' => "DALL'APPLICAZIONE: checksum"]],
], true) . ';');

$merged = new MvcTranslator('it', ['it'], $root . '/app/', ['rules' => $root . '/lib']);

test('a sentence only the library ships is found',
    $merged->get('rules.luhn.checksum') === 'DALLA LIBRERIA: luhn',
    'this is the case the application had lost by copying');

test('THE APPLICATION STILL WINS where it defines one',
    $merged->get('rules.iban.checksum') === "DALL'APPLICAZIONE: checksum",
    'merging beneath makes the shipped sentences a floor, not a straitjacket');

test('the application\'s other messages are untouched', $merged->get('hello') === 'ciao');

test('a translator with no groups behaves exactly as before',
    (new MvcTranslator('it', ['it'], $root . '/app/'))->get('rules.luhn.checksum') !== 'DALLA LIBRERIA: luhn');

test('a group whose directory has no file for this locale is skipped, not fatal',
    (new MvcTranslator('it', ['it'], $root . '/app/', ['nope' => $root . '/lib']))->get('hello') === 'ciao');

// -----------------------------------------------------------------------------
section('group(): a whole branch, for handing to something that is not PHP');

$branch = $merged->group('rules');

test('the branch carries both sources', isset($branch['iban'], $branch['luhn']));
test('…with the application\'s value where it has one',
    ($branch['iban']['checksum'] ?? '') === "DALL'APPLICAZIONE: checksum");

test('a missing key is an empty array, not an exception', $merged->group('nonexistent') === []);
test('a leaf is not a branch', $merged->group('hello') === [],
    'a page that renders no messages beats a page that does not render');

$nested = $merged->group('rules.iban');
test('a dotted path reaches a sub-branch', ($nested['checksum'] ?? '') === "DALL'APPLICAZIONE: checksum");

(static function (string $dir): void {
    foreach (glob($dir . '/*/*/*') ?: [] as $file) { @unlink($file); }
    foreach (glob($dir . '/*/*') ?: [] as $sub) { @rmdir($sub); }
    foreach (glob($dir . '/*') ?: [] as $sub) { @rmdir($sub); }
    @rmdir($dir);
})($root);

exit(summary());
