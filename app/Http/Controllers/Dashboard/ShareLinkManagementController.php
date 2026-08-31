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
 *   - highlight words: server-runtime tier (§0.2) — the client sends
 *     plaintext (the server needs it to recompute), and this controller is
 *     the one that calls Crypt::encryptString on it before storing.
 *
 * A share link's content key is never generated, stored, or handled here at
 * all — every link's key derives deterministically from its own id/
 * legacy_token (App\Services\Crypto\LegacyShareLinkKey). There's nothing to
 * "rotate" in the old fragment/passphrase sense, but the id/legacy_token
 * itself can still be replaced wholesale — see regenerateToken() — which
 * invalidates every URL out in the wild by construction, same net effect a
 * key rotation used to have.
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
            'bypass_dnd' => ['nullable', 'boolean'],
            'show_activity' => ['nullable', 'boolean'],
        ]);

        $shareLink = $request->user()->shareLinks()->create([
            'id' => $data['id'],
            'label_ciphertext' => $data['label_ciphertext'] ?? null,
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
     * Replaces the link's public identifier with a freshly generated
     * alphanumeric token (same generation method the old app used for
     * calendar_highlight_tokens.token: base64-encode random bytes, retry
     * until the result happens to contain only letters and digits — no
     * `+`/`/`/`=` to worry about escaping in a URL path segment). Since the
     * content key derives from `legacy_token ?? id` (LegacyShareLinkKey),
     * swapping in a new token also changes the derived key, so every URL
     * anyone already has — whether it used the old legacy_token or the
     * link's own id — stops decrypting immediately. Works on any link, not
     * just already-legacy ones: a link with no legacy_token yet gets one
     * for the first time, permanently switching its public URL from
     * `/free/{id}` to `/free/{token}`.
     */
    public function regenerateToken(Request $request, string $shareLink): JsonResponse
    {
        $shareLink = $this->findOwned($request, $shareLink);

        $shareLink->update(['legacy_token' => $this->generateLegacyStyleToken()]);

        ShareLinkCache::where('share_link_id', $shareLink->id)->delete();

        return response()->json($this->serializeForOwner($shareLink));
    }

    private function generateLegacyStyleToken(): string
    {
        do {
            $token = base64_encode(random_bytes(24));
        } while (! ctype_alnum($token) || ShareLink::where('legacy_token', $token)->exists());

        return $token;
    }

    public function destroy(Request $request, string $shareLink): JsonResponse
    {
        $this->findOwned($request, $shareLink)->delete();

        return response()->json(null, 204);
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

    private function findOwned(Request $request, string $id): ShareLink
    {
        return $request->user()->shareLinks()->where('id', $id)->firstOrFail();
    }
}
