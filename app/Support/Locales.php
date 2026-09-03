<?php

namespace App\Support;

/**
 * The single source of truth for every locale the public /free page (and
 * the owner-authored localized-text fields it renders — public_page_title,
 * ActivityLocalization::label, see App\Models\Concerns\HasLocalizedFields) can be
 * shown in. Mirrors App\Support\ColorPalette's own "PHP is the source of
 * truth, shared to the frontend via Inertia" shape (see
 * HandleInertiaRequests::share()'s 'locales' key) — LanguageSwitcher.vue's
 * dropdown and LocalizedTextInput.vue's per-row picker both read this list
 * rather than keeping their own copy.
 *
 * 'en' is always first and always the route-default/no-prefix locale (see
 * routes/web.php) — every other code here gets its own `/{locale}/free/...`
 * route registered automatically. Adding a language is just adding a row
 * here plus a matching lang/{code}.json — no route/controller change
 * needed.
 */
final class Locales
{
    /**
     * code => [native name, English name]. Order here is dropdown order.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const array NAMES = [
        'en' => 'English',
        'hu' => 'Magyar',
        'de' => 'Deutsch',
        'fr' => 'Français',
        'es' => 'Español',
        'it' => 'Italiano',
        'pt' => 'Português',
        'nl' => 'Nederlands',
        'pl' => 'Polski',
        'ro' => 'Română',
        'cs' => 'Čeština',
        'sk' => 'Slovenčina',
        'sv' => 'Svenska',
        'da' => 'Dansk',
        'no' => 'Norsk',
        'fi' => 'Suomi',
        'el' => 'Ελληνικά',
        'tr' => 'Türkçe',
        'ru' => 'Русский',
        'uk' => 'Українська',
        'ja' => '日本語',
        'zh' => '中文',
        'ko' => '한국어',
        'ar' => 'العربية',
        'he' => 'עברית',
        'hi' => 'हिन्दी',
    ];

    /** Right-to-left scripts — used to set the page's own dir attribute. */
    public const array RTL = ['ar', 'he'];

    public const string DEFAULT = 'en';

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::NAMES);
    }

    public static function isValid(string $code): bool
    {
        return array_key_exists($code, self::NAMES);
    }

    /** @return list<array{code: string, native: string, english: string}> */
    public static function forFrontend(): array
    {
        return array_map(
            fn (string $code, string $name) => ['code' => $code, 'native' => $name],
            array_keys(self::NAMES),
            array_values(self::NAMES),
        );
    }
}
