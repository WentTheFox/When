<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\ShareLink;
use App\Models\ShareLinkCache;
use App\Models\ShareLinkWord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Owner-facing share-link CRUD (Stage 7). Two encryption tiers meet here,
 * both documented on their migrations:
 *   - label_ciphertext: client-vault E2EE (§0.1/§0.3) — the client sends
 *     ciphertext it already produced with a per-link key from its own
 *     key ring; this controller never sees the plaintext label.
 *   - highlight words / manual tags / the raw content key: server-runtime
 *     tier (§0.2) — the client sends plaintext (the server needs it to
 *     recompute), and this controller is the one that calls Crypt::
 *     encryptString on it before storing.
 */
class ShareLinkManagementController extends Controller
{
    public function index(Request $request): Response
    {
        $shareLinks = $request->user()->shareLinks()
            ->with(['words', 'connection'])
            ->latest()
            ->get()
            ->map(fn (ShareLink $shareLink) => $this->serializeForOwner($shareLink));

        return Inertia::render('Dashboard/ShareLinks', [
            'shareLinks' => $shareLinks,
            // For the "tie to a connection" picker on each card — name is
            // still ciphertext (client-vault tier, §0.1), decrypted in the
            // browser same as everywhere else a connection name is shown.
            'connections' => $request->user()->connections()->get(['id', 'name_ciphertext']),
        ]);
    }

    /**
     * Words/tags are server-runtime tier (§0.2) — the owner is allowed to
     * see their own plaintext, this controller just isn't allowed to see
     * label_ciphertext's (client-vault tier, §0.3) plaintext.
     */
    private function serializeForOwner(ShareLink $shareLink): array
    {
        return [
            'id' => $shareLink->id,
            'label_ciphertext' => $shareLink->label_ciphertext,
            'key_protection' => $shareLink->key_protection,
            'archived' => $shareLink->archived,
            'bypass_dnd' => $shareLink->bypass_dnd,
            'show_activity' => $shareLink->show_activity,
            'legacy_token' => $shareLink->legacy_token,
            'connection_id' => $shareLink->connection?->id,
            'highlight_words' => $shareLink->words->map(
                fn (ShareLinkWord $word) => Crypt::decryptString($word->word_ciphertext),
            )->all(),
        ];
    }

    /**
     * The client generates the share link's id and, when applicable, the
     * label's key-ring entry BEFORE calling this — see vault.ts's
     * createRecordKey. That id is what ties the vault-encrypted label back
     * together after a fresh page load re-derives the vault key.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'uuid', 'unique:share_links,id'],
            'label_ciphertext' => ['nullable', 'string'],
            'content_key' => ['required', 'string'], // raw 32-byte AES key, base64 (not base64url)
            'key_protection' => ['required', 'in:fragment,passphrase'],
            'wrapped_key' => ['nullable', 'string', 'required_if:key_protection,passphrase'],
            'wrap_salt' => ['nullable', 'string', 'required_if:key_protection,passphrase'],
            'bypass_dnd' => ['nullable', 'boolean'],
            'show_activity' => ['nullable', 'boolean'],
        ]);

        $shareLink = $request->user()->shareLinks()->create([
            'id' => $data['id'],
            'label_ciphertext' => $data['label_ciphertext'] ?? null,
            'content_key_ciphertext' => Crypt::encryptString($data['content_key']),
            'key_protection' => $data['key_protection'],
            'wrapped_key' => $data['wrapped_key'] ?? null,
            'wrap_salt' => $data['wrap_salt'] ?? null,
            'bypass_dnd' => $data['bypass_dnd'] ?? false,
            'show_activity' => $data['show_activity'] ?? true,
        ]);

        return response()->json($this->serializeForOwner($shareLink), 201);
    }

    public function update(Request $request, string $shareLink): JsonResponse
    {
        $shareLink = $this->findOwned($request, $shareLink);

        $data = $request->validate([
            'label_ciphertext' => ['nullable', 'string'],
            'bypass_dnd' => ['nullable', 'boolean'],
            'show_activity' => ['nullable', 'boolean'],
            'archived' => ['nullable', 'boolean'],
            'key_protection' => ['nullable', 'in:fragment,passphrase'],
            'wrapped_key' => ['nullable', 'string'],
            'wrap_salt' => ['nullable', 'string'],
            'highlight_words' => ['nullable', 'array'],
            'highlight_words.*' => ['string'],
        ]);

        $this->applyUpdate($shareLink, $data);

        return response()->json($this->serializeForOwner($shareLink->refresh()));
    }

    /** @param  array<string, mixed>  $data  Same shape update() validates. */
    private function applyUpdate(ShareLink $shareLink, array $data): void
    {
        DB::transaction(function () use ($shareLink, $data) {
            $shareLink->fill(array_filter([
                'label_ciphertext' => $data['label_ciphertext'] ?? null,
                'bypass_dnd' => $data['bypass_dnd'] ?? null,
                'show_activity' => $data['show_activity'] ?? null,
                'archived' => $data['archived'] ?? null,
                'key_protection' => $data['key_protection'] ?? null,
                'wrapped_key' => $data['wrapped_key'] ?? null,
                'wrap_salt' => $data['wrap_salt'] ?? null,
            ], fn ($value) => $value !== null))->save();

            if (array_key_exists('highlight_words', $data)) {
                $shareLink->words()->delete();
                foreach ($data['highlight_words'] as $word) {
                    ShareLinkWord::create([
                        'share_link_id' => $shareLink->id,
                        'word_ciphertext' => Crypt::encryptString($word),
                    ]);
                }
            }
        });

        // Any of the above can change the computed result — never serve a
        // stale cache after an owner-initiated edit (§5.3's job re-derives
        // it lazily on the next viewer request).
        ShareLinkCache::where('share_link_id', $shareLink->id)->delete();
    }

    /**
     * Rotates the content key. Invalidates every existing viewer link
     * (fragment or passphrase-wrapped) immediately — there is no way to
     * "update" a fragment a viewer already has.
     */
    public function regenerateKey(Request $request, string $shareLink): JsonResponse
    {
        $shareLink = $this->findOwned($request, $shareLink);

        if ($shareLink->legacy_token !== null) {
            return response()->json([
                'message' => 'Legacy links derive their key from the token itself and cannot be rotated.',
            ], 422);
        }

        $data = $request->validate([
            'content_key' => ['required', 'string'],
            'key_protection' => ['required', 'in:fragment,passphrase'],
            'wrapped_key' => ['nullable', 'string', 'required_if:key_protection,passphrase'],
            'wrap_salt' => ['nullable', 'string', 'required_if:key_protection,passphrase'],
        ]);

        $shareLink->update([
            'content_key_ciphertext' => Crypt::encryptString($data['content_key']),
            'key_protection' => $data['key_protection'],
            'wrapped_key' => $data['wrapped_key'] ?? null,
            'wrap_salt' => $data['wrap_salt'] ?? null,
        ]);

        ShareLinkCache::where('share_link_id', $shareLink->id)->delete();

        return response()->json($this->serializeForOwner($shareLink));
    }

    /**
     * Exports each link's plaintext-tier config (words/tags/bypass_dnd/
     * show_activity) plus
     * the label as still-encrypted ciphertext — "adjusted for encrypted
     * shapes" per PLAN.md, since this controller has no way to decrypt a
     * client-vault-encrypted label and shouldn't gain one.
     */
    public function export(Request $request): JsonResponse
    {
        $shareLinks = $request->user()->shareLinks()->with('words')->get();

        return response()->json([
            'share_links' => $shareLinks->map(fn (ShareLink $shareLink) => [
                'id' => $shareLink->id,
                'label_ciphertext' => $shareLink->label_ciphertext,
                'bypass_dnd' => $shareLink->bypass_dnd,
                'show_activity' => $shareLink->show_activity,
                'highlight_words' => $shareLink->words->map(
                    fn (ShareLinkWord $word) => Crypt::decryptString($word->word_ciphertext),
                )->all(),
            ])->all(),
        ]);
    }

    /** Re-imports {@see export()}'s shape onto matching, already-existing links (by id). Never creates new links. */
    public function import(Request $request): JsonResponse
    {
        $data = $request->validate([
            'share_links' => ['required', 'array'],
            'share_links.*.id' => ['required', 'uuid'],
            'share_links.*.label_ciphertext' => ['nullable', 'string'],
            'share_links.*.bypass_dnd' => ['nullable', 'boolean'],
            'share_links.*.show_activity' => ['nullable', 'boolean'],
            'share_links.*.highlight_words' => ['nullable', 'array'],
            'share_links.*.highlight_words.*' => ['string'],
        ]);

        $imported = 0;
        $skipped = 0;

        foreach ($data['share_links'] as $row) {
            $shareLink = $request->user()->shareLinks()->where('id', $row['id'])->first();

            if ($shareLink === null) {
                $skipped++;

                continue;
            }

            $this->applyUpdate($shareLink, $row);

            $imported++;
        }

        return response()->json(['imported' => $imported, 'skipped' => $skipped]);
    }

    /**
     * Reconstructs the full viewer URL, including the fragment key for
     * fragment-protected links. Safe: the server already holds
     * content_key_ciphertext under the same runtime key as calendar_url
     * (§0.2) — this is that tier's documented "not protected against a
     * compromised runtime" trade-off, not a new exposure.
     *
     * Uses legacy_token (not id) as the path segment when one exists —
     * ShareLinkController::show() happily resolves either, so building the
     * URL from `id` isn't *broken*, but it hands the owner a second, brand
     * new URL nobody they've actually shared the link with has, instead of
     * the one already in circulation. Preserving the original token here
     * is the whole point of keeping it around after the Stage 5 migration.
     */
    public function url(Request $request, string $shareLink): JsonResponse
    {
        $shareLink = $this->findOwned($request, $shareLink);

        $path = route('share-links.show', $shareLink->legacy_token ?? $shareLink->id);

        if ($shareLink->key_protection !== 'fragment' || $shareLink->content_key_ciphertext === null) {
            return response()->json(['url' => $path]);
        }

        // Same bytes, just re-encoded from standard base64 (how it's stored)
        // to base64url (how fragment.ts's importKeyFromFragment expects it).
        $rawKeyBase64 = Crypt::decryptString($shareLink->content_key_ciphertext);
        $fragmentKey = rtrim(str_replace(['+', '/'], ['-', '_'], $rawKeyBase64), '=');

        return response()->json(['url' => "{$path}#k={$fragmentKey}"]);
    }

    private function findOwned(Request $request, string $id): ShareLink
    {
        return $request->user()->shareLinks()->where('id', $id)->firstOrFail();
    }
}
