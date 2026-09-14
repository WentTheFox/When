<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Enforcement point for lang/*.json staying in sync with lang/en.json —
 * the source of truth every other locale is measured against. Nothing
 * else in the app catches a locale silently missing a key: a commit that
 * only touches en.json never breaks the build, it just quietly regresses
 * UX for every non-English viewer until someone notices (see the free.*
 * keys added by 9cbcc4a and left untranslated everywhere else for days —
 * noticed only because the viewer saw the raw key itself, e.g.
 * "free.refreshNow", not English text: resources/js/app.ts's
 * `fallbackLang: 'en'` alone only covers a locale that fails to resolve
 * entirely, not an individual missing key within an otherwise-loaded
 * locale — that needs `fallbackMissingTranslations: true` too, now set
 * there as the runtime safety net this test doesn't replace).
 *
 * Deliberately a plain PHPUnit\Framework\TestCase (no Laravel bootstrap,
 * no DB) — same reasoning as HighlightMatcherTest: this is pure file
 * content, not app behavior, so it should never need Postgres/Redis to
 * run, in CI or otherwise.
 */
class LangParityTest extends TestCase
{
    private const LANG_DIR = __DIR__.'/../../lang';

    /** @return array<string, array<string, string>> */
    private function loadAll(): array
    {
        $files = glob(self::LANG_DIR.'/*.json');
        $this->assertNotEmpty($files, 'Expected to find lang/*.json files.');

        $locales = [];

        foreach ($files as $file) {
            $code = basename($file, '.json');
            $contents = file_get_contents($file);
            $decoded = json_decode($contents, true);

            $this->assertIsArray($decoded, "lang/{$code}.json is not valid JSON.");

            $locales[$code] = $decoded;
        }

        return $locales;
    }

    /**
     * Every placeholder a translated string was built around (`:offset`,
     * `:minutes`, etc.) has to survive translation — a translator dropping
     * one silently breaks that string's runtime substitution instead of
     * failing loudly, which is exactly the kind of regression this file
     * exists to catch before it reaches a locale file.
     *
     * @return string[]
     */
    private function placeholders(string $value): array
    {
        preg_match_all('/:[a-zA-Z_]+/', $value, $matches);
        sort($matches[0]);

        return $matches[0];
    }

    public function test_every_locale_has_every_key_english_has(): void
    {
        $locales = $this->loadAll();
        $this->assertArrayHasKey('en', $locales, 'lang/en.json must exist — it is the source of truth every other locale is checked against.');
        $enKeys = array_keys($locales['en']);

        $problems = [];

        foreach ($locales as $code => $strings) {
            if ($code === 'en') {
                continue;
            }

            $missing = array_diff($enKeys, array_keys($strings));

            if ($missing !== []) {
                $problems[] = "{$code}.json is missing: ".implode(', ', $missing);
            }
        }

        $this->assertSame([], $problems, "Every lang/*.json file must carry every key lang/en.json has — a commit that adds a key to en.json must add it everywhere else too, in the same commit.\n\n".implode("\n", $problems));
    }

    public function test_no_locale_has_a_key_english_does_not(): void
    {
        $locales = $this->loadAll();
        $enKeys = array_keys($locales['en']);

        $problems = [];

        foreach ($locales as $code => $strings) {
            if ($code === 'en') {
                continue;
            }

            $extra = array_diff(array_keys($strings), $enKeys);

            if ($extra !== []) {
                $problems[] = "{$code}.json has keys en.json does not: ".implode(', ', $extra);
            }
        }

        $this->assertSame([], $problems, "A stray key (typo, or a key removed from en.json but never cleaned up elsewhere) never gets used by the app — remove it, or add it to en.json if it's genuinely needed.\n\n".implode("\n", $problems));
    }

    public function test_no_translated_value_is_blank(): void
    {
        $locales = $this->loadAll();

        $problems = [];

        foreach ($locales as $code => $strings) {
            foreach ($strings as $key => $value) {
                if (! is_string($value) || trim($value) === '') {
                    $problems[] = "{$code}.json: \"{$key}\" is blank.";
                }
            }
        }

        $this->assertSame([], $problems, "A blank value is indistinguishable from a real translation to the JSON-parity check above but renders as nothing in the UI.\n\n".implode("\n", $problems));
    }

    public function test_every_translated_value_keeps_the_same_placeholders_as_english(): void
    {
        $locales = $this->loadAll();
        $en = $locales['en'];

        $problems = [];

        foreach ($locales as $code => $strings) {
            if ($code === 'en') {
                continue;
            }

            foreach ($en as $key => $enValue) {
                if (! isset($strings[$key]) || ! is_string($strings[$key])) {
                    continue; // Already reported by test_every_locale_has_every_key_english_has.
                }

                $expected = $this->placeholders($enValue);

                if ($expected === []) {
                    continue;
                }

                $actual = $this->placeholders($strings[$key]);

                if ($actual !== $expected) {
                    $problems[] = "{$code}.json: \"{$key}\" has placeholders [".implode(', ', $actual).'] but en.json has ['.implode(', ', $expected).'].';
                }
            }
        }

        $this->assertSame([], $problems, "A translated string must substitute the exact same :placeholders as its English original — dropping or renaming one breaks that string's runtime substitution silently.\n\n".implode("\n", $problems));
    }
}
