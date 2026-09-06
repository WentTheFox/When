<?php

namespace Tests\Feature;

use App\Models\ShareLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * wtf-locale is excepted from EncryptCookies (bootstrap/app.php) —
 * LanguageSwitcher.vue writes it directly via plain document.cookie, so
 * the server has to read/write the same plaintext format or an explicit
 * language switch silently gets lost (see that middleware exception's own
 * doc comment for the full story). Every incoming cookie here uses
 * withUnencryptedCookie(), not withCookie() (which auto-encrypts) — the
 * real client never sends an encrypted value, so a test that did would
 * pass without ever exercising what actually happens in production.
 * assertCookie() likewise passes `false` for its $encrypted argument.
 */
class ShareLinkLocaleDetectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_hungarian_accept_language_redirects_the_english_path_to_hungarian(): void
    {
        $shareLink = ShareLink::factory()->for(User::factory())->create();

        $this->withHeaders(['Accept-Language' => 'hu,en;q=0.5'])
            ->get("/free/{$shareLink->highlight_token}?at=2026-01-01")
            ->assertRedirect("/hu/free/{$shareLink->highlight_token}?at=2026-01-01")
            ->assertCookie('wtf-locale', 'hu', false);
    }

    public function test_an_english_accept_language_never_redirects_the_hungarian_path_to_english(): void
    {
        $shareLink = ShareLink::factory()->for(User::factory())->create();

        // Detection only ever promotes /free up to /hu — an explicit /hu
        // visit is a deliberate signal (a shared link, a bookmark, a
        // manual language switch) that a mismatched browser language must
        // never silently override.
        $this->withHeaders(['Accept-Language' => 'en-US,en;q=0.5'])
            ->get("/hu/free/{$shareLink->highlight_token}")
            ->assertOk()
            ->assertCookie('wtf-locale', 'hu', false);
    }

    public function test_a_stored_cookie_overrides_accept_language(): void
    {
        $shareLink = ShareLink::factory()->for(User::factory())->create();

        // Accept-Language prefers Hungarian, but an explicit prior choice
        // (cookie) — e.g. from LanguageSwitcher.vue — always wins.
        $this->withUnencryptedCookie('wtf-locale', 'en')
            ->withHeaders(['Accept-Language' => 'hu,en;q=0.5'])
            ->get("/free/{$shareLink->highlight_token}")
            ->assertOk();
    }

    public function test_an_english_cookie_never_redirects_the_hungarian_path_to_english(): void
    {
        $shareLink = ShareLink::factory()->for(User::factory())->create();

        // Same guard as the Accept-Language case above, but for a stored
        // cookie preference from an earlier visit — still must not demote
        // an explicit /hu visit.
        $this->withUnencryptedCookie('wtf-locale', 'en')
            ->get("/hu/free/{$shareLink->highlight_token}")
            ->assertOk()
            ->assertCookie('wtf-locale', 'hu', false);
    }

    public function test_visiting_the_correct_locale_directly_sets_the_cookie_without_redirecting(): void
    {
        $shareLink = ShareLink::factory()->for(User::factory())->create();

        $this->withHeaders(['Accept-Language' => 'hu,en;q=0.5'])
            ->get("/hu/free/{$shareLink->highlight_token}")
            ->assertOk()
            ->assertCookie('wtf-locale', 'hu', false);
    }
}
