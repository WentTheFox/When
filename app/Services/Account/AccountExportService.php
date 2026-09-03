<?php

namespace App\Services\Account;

use App\Models\CalendarDetection;
use App\Models\ConnectionAttributeValue;
use App\Models\InviteRedemption;
use App\Models\ShareLinkWord;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use ZipStream\ZipStream;

/**
 * Builds the "request my data" export as a stream of zip entries — nothing
 * is ever buffered whole in memory or written to a real file, matching
 * AccountExportController's use of response()->streamDownload().
 *
 * Three encryption tiers, one per file's top-level "tier" key:
 *   - "plaintext": stored and exported as-is.
 *   - "server-decrypted": was Crypt::encryptString/APP_KEY ciphertext (§0.2)
 *     — this process can legitimately decrypt it, so it's exported already
 *     readable.
 *   - "e2ee": still encrypted. Each record's *_ciphertext fields are
 *     encrypted with a per-record AES-256-GCM key pulled from the owner's
 *     key ring (resources/js/crypto/keyring.ts), not the vault key
 *     directly — "key_ring_id" on each record names which key-ring entry
 *     to use. README.txt spells out the exact steps.
 *
 * IMPORTANT: everything decrypted here (name/email/calendar URL/2FA
 * secrets) is written straight into the zip stream and never logged or
 * persisted anywhere else — same discipline as
 * RecomputeShareLinkAvailability's plaintext handling.
 */
class AccountExportService
{
    public function build(ZipStream $zip, User $user): void
    {
        $zip->addFile('README.txt', $this->readme(now()));
        $zip->addFile('decrypt_export.py', file_get_contents(__DIR__.'/scripts/decrypt_export.py'));
        $zip->addFile('decrypt_export.php', file_get_contents(__DIR__.'/scripts/decrypt_export.php'));
        $zip->addFile('requirements.txt', file_get_contents(__DIR__.'/scripts/requirements.txt'));

        $zip->addFile('account/profile.json', $this->json($this->profile($user)));
        $zip->addFile('account/security.json', $this->json($this->security($user)));
        $zip->addFile('account/calendar-url.json', $this->json($this->calendarUrl($user)));
        $zip->addFile('account/key-parameters.json', $this->json($this->keyParameters($user)));
        $zip->addFile('account/invites-issued.json', $this->json($this->invitesIssued($user)));
        $zip->addFile('account/invite-redemptions.json', $this->json($this->inviteRedemptions($user)));
        $zip->addFile('account/calendar-detections.json', $this->json($this->calendarDetections($user)));

        $zip->addFile('availability/sleep-exceptions.json', $this->json($this->sleepExceptions($user)));
        $zip->addFile('availability/activity-roles.json', $this->json($this->activityRoles($user)));

        $shareLinkIds = $user->shareLinks()->pluck('id');

        $zip->addFile('share-links/share-links.json', $this->json($this->shareLinks($user)));
        $zip->addFile('share-links/share-link-words.json', $this->json($this->shareLinkWords($shareLinkIds)));
        $zip->addFile('share-links/share-link-cache-note.txt', $this->shareLinkCacheNote());

        $zip->addFile('connections/connections.json', $this->json($this->connections($user)));
        $zip->addFile('connections/sources.json', $this->json($this->connectionSources($user)));
        $zip->addFile('connections/source-categories.json', $this->json($this->connectionSourceCategories($user)));
        $zip->addFile('connections/attribute-definitions.json', $this->json($this->connectionAttributeDefinitions($user)));
        $zip->addFile('connections/attribute-values.json', $this->json($this->connectionAttributeValues($user)));
        $zip->addFile('connections/edges.json', $this->json($this->connectionEdges($user)));
        $zip->addFile('connections/source-links.json', $this->json($this->connectionSourceLinks($user)));
    }

    /** @param array<string, mixed> $data */
    private function json(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /** @return array<string, mixed> */
    private function profile(User $user): array
    {
        return [
            'tier' => 'server-decrypted',
            'records' => [[
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'timezone' => $user->timezone,
                'week_start' => $user->week_start,
                'dnd_event_pattern' => $user->dnd_event_pattern,
                'nap_event_pattern' => $user->nap_event_pattern,
                'work_event_pattern' => $user->work_event_pattern,
                'school_event_pattern' => $user->school_event_pattern,
                'availability_settings' => $user->availability_settings,
                'calendar_parsing_mode' => $user->calendar_parsing_mode,
                'highlight_clause_pattern' => $user->highlight_clause_pattern,
                'highlight_split_pattern' => $user->highlight_split_pattern,
                'activity_clause_pattern' => $user->activity_clause_pattern,
                'tentative_pattern' => $user->tentative_pattern,
                'open_end_pattern' => $user->open_end_pattern,
                'open_start_pattern' => $user->open_start_pattern,
                'accent_color_key' => $user->accent_color_key,
                'secondary_color_key' => $user->secondary_color_key,
                'sleep_color_key' => $user->sleep_color_key,
                'busy_color_key' => $user->busy_color_key,
                'work_color_key' => $user->work_color_key,
                'school_color_key' => $user->school_color_key,
                'free_color_key' => $user->free_color_key,
                'highlight_color_key' => $user->highlight_color_key,
                'free_icon_key' => $user->free_icon_key,
                'busy_icon_key' => $user->busy_icon_key,
                'work_icon_key' => $user->work_icon_key,
                'school_icon_key' => $user->school_icon_key,
                'sleep_icon_key' => $user->sleep_icon_key,
                'highlight_icon_key' => $user->highlight_icon_key,
                'now_color_key' => $user->now_color_key,
                'public_page_title' => $user->public_page_title,
                'created_at' => $user->created_at,
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function security(User $user): array
    {
        return [
            'tier' => 'server-decrypted',
            'sensitive' => 'SENSITIVE — these recovery codes and the TOTP secret can be used to bypass your two-factor authentication. Treat this file like a password.',
            'records' => [[
                'two_factor_secret' => $user->two_factor_secret,
                'two_factor_recovery_codes' => $user->two_factor_recovery_codes,
                'two_factor_confirmed_at' => $user->two_factor_confirmed_at,
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function calendarUrl(User $user): array
    {
        return [
            'tier' => 'server-decrypted',
            'sensitive' => 'SENSITIVE — this is your live calendar feed URL. Anyone with it can read your raw calendar.',
            'records' => [[
                'calendar_url' => $user->calendar_url_ciphertext === null
                    ? null
                    : Crypt::decryptString($user->calendar_url_ciphertext),
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function keyParameters(User $user): array
    {
        return [
            'tier' => 'e2ee',
            'note' => 'Needed to reconstruct your key ring offline — see README.txt.',
            'records' => [[
                'passphrase_salt' => $user->passphrase_salt,
                'verifier_salt_version' => $user->verifier_salt_version,
                'key_ring_ciphertext' => $user->key_ring_ciphertext,
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function invitesIssued(User $user): array
    {
        return [
            'tier' => 'plaintext',
            'records' => $user->invitesIssued()->get([
                'id', 'code', 'max_uses', 'used_at', 'expires_at', 'source_share_link_id', 'created_at',
            ])->toArray(),
        ];
    }

    /** @return array<string, mixed> */
    private function inviteRedemptions(User $user): array
    {
        return [
            'tier' => 'plaintext',
            'records' => InviteRedemption::where('user_id', $user->id)
                ->get(['id', 'invite_id', 'redeemed_at'])
                ->toArray(),
        ];
    }

    /** @return array<string, mixed> */
    private function calendarDetections(User $user): array
    {
        return [
            'tier' => 'plaintext',
            'records' => CalendarDetection::where('user_id', $user->id)
                ->get(['id', 'detected_mode', 'fetched_at'])
                ->toArray(),
        ];
    }

    /** @return array<string, mixed> */
    private function sleepExceptions(User $user): array
    {
        return [
            'tier' => 'e2ee',
            'records' => $user->sleepExceptions()
                ->get(['id', 'start_date', 'end_date', 'label_ciphertext'])
                ->map(fn ($row) => [
                    'id' => $row->id,
                    'start_date' => $row->start_date,
                    'end_date' => $row->end_date,
                    'label_ciphertext' => $row->label_ciphertext,
                    'key_ring_id' => $row->id,
                ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function activityRoles(User $user): array
    {
        return [
            'tier' => 'plaintext',
            'records' => $user->activityRoles->map(fn ($role) => [
                'id' => $role->id,
                'pattern' => $role->pattern,
                'sort_order' => $role->sort_order,
                'label' => $role->label,
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function shareLinks(User $user): array
    {
        return [
            'tier' => 'e2ee',
            'note' => 'label_ciphertext is e2ee (key_ring_id below). Every other field on a share link is plaintext.',
            'records' => $user->shareLinks()
                ->get(['id', 'highlight_token', 'label_ciphertext', 'archived', 'bypass_dnd', 'show_activity', 'created_at'])
                ->map(fn ($link) => [
                    'id' => $link->id,
                    'highlight_token' => $link->highlight_token,
                    'label_ciphertext' => $link->label_ciphertext,
                    'key_ring_id' => $link->id,
                    'archived' => $link->archived,
                    'bypass_dnd' => $link->bypass_dnd,
                    'show_activity' => $link->show_activity,
                    'created_at' => $link->created_at,
                ])->all(),
        ];
    }

    /**
     * @param  Collection<int, string>  $shareLinkIds
     * @return array<string, mixed>
     */
    private function shareLinkWords(Collection $shareLinkIds): array
    {
        // word_ciphertext is server-runtime tier (§0.2, Crypt/APP_KEY) —
        // NOT vault-key E2EE — see ShareLinkManagementController's own doc
        // comment. Decrypted here the same way
        // RecomputeShareLinkAvailability does.
        $words = ShareLinkWord::whereIn('share_link_id', $shareLinkIds)
            ->get(['id', 'share_link_id', 'word_ciphertext']);

        return [
            'tier' => 'server-decrypted',
            'records' => $words->map(fn ($word) => [
                'id' => $word->id,
                'share_link_id' => $word->share_link_id,
                'word' => Crypt::decryptString($word->word_ciphertext),
            ])->all(),
        ];
    }

    private function shareLinkCacheNote(): string
    {
        return <<<'TXT'
            About share_link_cache (not included in this export)
            =====================================================

            Each share link's cached computed-availability ciphertext is encrypted
            with a content key derived from that link's own highlight_token/id (see
            app/Services/Crypto/HighlightTokenKey.php), not your account's vault key.
            There is nothing in this export that can decrypt it. If you need the
            underlying availability data for a link, view the share link itself while
            it's still active.
            TXT;
    }

    /**
     * connections no longer has its own source_id column — a connection's
     * sources are the many-to-many connection_source_links pivot instead
     * (connections/source-links.json), not a field on this record.
     *
     * @return array<string, mixed>
     */
    private function connections(User $user): array
    {
        return [
            'tier' => 'e2ee',
            'records' => $user->connections()
                ->get(['id', 'share_link_id', 'name_ciphertext', 'notes_ciphertext', 'archived', 'created_at'])
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'share_link_id' => $c->share_link_id,
                    'name_ciphertext' => $c->name_ciphertext,
                    'notes_ciphertext' => $c->notes_ciphertext,
                    'key_ring_id' => $c->id,
                    'archived' => $c->archived,
                    'created_at' => $c->created_at,
                ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function connectionSources(User $user): array
    {
        return [
            'tier' => 'e2ee',
            'records' => $user->connectionSources()
                ->get(['id', 'category_id', 'name_ciphertext'])
                ->map(fn ($s) => [
                    'id' => $s->id,
                    'category_id' => $s->category_id,
                    'name_ciphertext' => $s->name_ciphertext,
                    'key_ring_id' => $s->id,
                ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function connectionSourceCategories(User $user): array
    {
        return [
            'tier' => 'e2ee',
            'records' => $user->connectionSourceCategories()
                ->get(['id', 'name_ciphertext'])
                ->map(fn ($cat) => [
                    'id' => $cat->id,
                    'name_ciphertext' => $cat->name_ciphertext,
                    'key_ring_id' => $cat->id,
                ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function connectionAttributeDefinitions(User $user): array
    {
        return [
            'tier' => 'e2ee',
            'records' => $user->connectionAttributeDefinitions()
                ->get(['id', 'label_ciphertext', 'type', 'options_ciphertext'])
                ->map(fn ($def) => [
                    'id' => $def->id,
                    'label_ciphertext' => $def->label_ciphertext,
                    'type' => $def->type,
                    'options_ciphertext' => $def->options_ciphertext,
                    'key_ring_id' => $def->id,
                ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function connectionAttributeValues(User $user): array
    {
        $connectionIds = $user->connections()->pluck('id');

        $values = ConnectionAttributeValue::whereIn('connection_id', $connectionIds)
            ->get(['id', 'connection_id', 'attribute_definition_id', 'value_ciphertext']);

        return [
            'tier' => 'e2ee',
            'note' => 'key_ring_id is the PARENT connection\'s id, not this record\'s own id.',
            'records' => $values->map(fn ($v) => [
                'id' => $v->id,
                'connection_id' => $v->connection_id,
                'attribute_definition_id' => $v->attribute_definition_id,
                'value_ciphertext' => $v->value_ciphertext,
                'key_ring_id' => $v->connection_id,
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function connectionEdges(User $user): array
    {
        return [
            'tier' => 'e2ee',
            'records' => $user->connectionEdges()
                ->get(['id', 'from_connection_id', 'to_connection_id', 'label_ciphertext'])
                ->map(fn ($edge) => [
                    'id' => $edge->id,
                    'from_connection_id' => $edge->from_connection_id,
                    'to_connection_id' => $edge->to_connection_id,
                    'label_ciphertext' => $edge->label_ciphertext,
                    'key_ring_id' => $edge->id,
                ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function connectionSourceLinks(User $user): array
    {
        $connectionIds = $user->connections()->pluck('id');

        return [
            'tier' => 'plaintext',
            'records' => DB::table('connection_source_links')
                ->whereIn('connection_id', $connectionIds)
                ->get(['connection_id', 'source_id'])
                ->toArray(),
        ];
    }

    private function readme(CarbonInterface $requestedAt): string
    {
        $requestedAtDisplay = Carbon::instance($requestedAt)->utc()->format('Y-m-d H:i:s \U\T\C');

        return <<<TXT
            When — your data export
            ========================

            This zip is a snapshot of everything tied to your account, as of the
            moment you requested it ({$requestedAtDisplay}).

            Three kinds of fields, marked by each JSON file's own "tier":

              - "plaintext": stored and shown here exactly as-is.
              - "server-decrypted": was encrypted at rest with the server's own key
                (never your master password) — decrypted before export since the
                server can legitimately do that. This includes your name, email,
                calendar URL, 2FA secret/recovery codes, and share-link highlight
                words.
              - "e2ee": still encrypted. Decrypting it yourself takes a few steps —
                see below. This covers connection names/notes, source and category
                names, attribute labels/options/values, connection-edge labels,
                sleep-exception labels, and share-link labels.

            SENSITIVE FILES — handle these like passwords:
              - account/security.json contains your two-factor secret and recovery
                codes. Anyone with them can bypass your two-factor authentication.
              - account/calendar-url.json contains your live calendar feed URL.
                Anyone with it can read your raw calendar.

            How to decrypt the "e2ee" files
            --------------------------------

            Everything below only needs your master password and standard tools —
            you don't need to run this app's own code.

            1. Derive your vault key from your master password and
               account/key-parameters.json's "passphrase_salt", using Argon2id:
                 memory: 19456 KiB, iterations: 2, parallelism: 1, output: 32 bytes
               (base64-decode passphrase_salt first — it's the raw Argon2id salt.)

            2. account/key-parameters.json's "key_ring_ciphertext" is base64. Decode
               it, then split it into a 12-byte IV (the first 12 bytes) and the rest
               (AES-256-GCM ciphertext, with a 16-byte authentication tag appended
               by the encryption itself — there's no separately stored tag field).
               AES-256-GCM-decrypt it with your vault key from step 1 and that IV.
               The result is a JSON object: { "<recordId>": "<base64 raw AES key>" }
               — this is your key ring.

            3. Every e2ee record below carries its own "key_ring_id". Look that id
               up in the key ring from step 2 to get that record's own raw AES-256
               key (base64-decode it). Decrypt each of that record's own
               "*_ciphertext" fields the same way as step 2 (base64-decode, first 12
               bytes = IV, rest = AES-256-GCM ciphertext+tag), using that record's
               own key instead of the vault key.

            Included scripts
            -----------------

            decrypt_export.py and decrypt_export.php, right next to this README,
            both do all three steps above for every "e2ee" file in this zip —
            pick whichever you have handy. Each writes a "<file>.decrypted.json"
            copy next to every file it processes.

              Python: pip install -r requirements.txt
                      python3 decrypt_export.py
              PHP:    needs the sodium and openssl extensions (bundled by
                      default since PHP 7.2) — no packages to install.
                      php decrypt_export.php

            Run either from inside this unzipped folder. Both prompt for your
            master password (never shown on screen, never written anywhere).

            Note: connections/attribute-values.json's records use their PARENT
            connection's id as "key_ring_id", not their own id — an attribute
            value's key ring entry is shared with the connection it belongs to.

            About share_link_cache: see share-links/share-link-cache-note.txt — it
            is not included here and cannot be decrypted with anything in this zip.

            This file was generated at export time. It does not update itself —
            request a fresh export any time you want a current copy (limited to 5
            downloads per day).
            TXT;
    }
}
