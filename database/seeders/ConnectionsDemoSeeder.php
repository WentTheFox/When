<?php

namespace Database\Seeders;

use App\Models\Connection;
use App\Models\ConnectionAttributeDefinition;
use App\Models\ConnectionAttributeValue;
use App\Models\ConnectionEdge;
use App\Models\ConnectionSource;
use App\Models\ConnectionSourceCategory;
use App\Models\ConnectionVrchatStay;
use App\Models\DiscordFriend;
use App\Models\ShareLink;
use App\Models\User;
use App\Services\Crypto\AesGcm;
use App\Support\DevMockVrchat;
use App\Support\DiscordUserId;
use App\Support\VrchatUserId;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Fills a LOCAL database with one demo owner and a realistic Connections CRM
 * (categories, sources, every custom-field type, connections, "knows" edges,
 * share links), for development and demos.
 *
 * Everything E2EE (§0.1) is encrypted here exactly the way the browser does
 * it: a random AES-256 key per record, collected into a key ring that is
 * itself encrypted with the vault key (Argon2id of the master password + the
 * user's passphrase_salt). So the demo account can be logged into and
 * unlocked like any real one. The login verifier is derived the same way as
 * resources/js/crypto/argon2.ts#deriveLoginVerifier (salt = the user's id).
 *
 * All names, handles and notes are invented. Never run this against a
 * database that holds real data: it refuses to run in production, and it
 * replaces the demo user's data on every run.
 *
 *   php artisan migrate:fresh --seed
 *   php artisan db:seed --class=ConnectionsDemoSeeder
 *
 * Login: name `demo`, master password `demo-password`.
 */
class ConnectionsDemoSeeder extends Seeder
{
    public const DEMO_NAME = 'demo';

    public const DEMO_PASSWORD = 'demo-password';

    private const CONNECTION_COUNT = 28;

    /** @var array<string, string> record id => raw 32-byte key */
    private array $keyRing = [];

    private string $passphraseSalt = '';

    /** @var array<string, true> usernames handed out so far, so no two connections share one */
    private array $usernames = [];

    private const ADJECTIVES = ['quiet', 'mossy', 'pixel', 'neon', 'sleepy', 'cosmic', 'tiny', 'velvet', 'rusty', 'lunar', 'frosty', 'amber', 'clever', 'wobbly', 'golden', 'misty'];

    private const NOUNS = ['fox', 'lynx', 'otter', 'owl', 'moth', 'comet', 'bean', 'sprout', 'kettle', 'pebble', 'raven', 'toad', 'ember', 'wisp', 'gecko', 'finch'];

    private function encryptFor(string $recordId, string $plaintext): string
    {
        return AesGcm::encrypt($this->keyRing[$recordId] ??= random_bytes(32), $plaintext);
    }

    /** Argon2id with the app's profile (resources/js/crypto/argon2.ts ARGON2ID_PROFILE). */
    private function argon2(string $password, string $rawSalt): string
    {
        return sodium_crypto_pwhash(32, $password, $rawSalt, 2, 19456 * 1024, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
    }

    /**
     * Same padding the browser applies to list values (resources/js/dashboard/encryptedList.ts).
     *
     * @param  list<string>  $entries
     */
    private function paddedList(array $entries): string
    {
        $body = implode("\n", $entries);
        $length = (int) (ceil((strlen($body) + 1) / 256) * 256);

        return str_pad($body, $length, "\n");
    }

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('ConnectionsDemoSeeder refuses to run in production.');
        }

        fake()->seed(20261107);
        $this->keyRing = [];
        $this->usernames = [];

        DB::transaction(function () {
            // Replace a previous demo account (and everything hanging off it) instead of piling up duplicates.
            User::withTrashed()->whereName(self::DEMO_NAME)->get()->each->forceDelete();

            $user = $this->createUser();
            $categories = $this->createCategories($user);
            $sources = $this->createSources($user, $categories);
            $definitions = $this->createDefinitions($user);
            $connections = $this->createConnections($user, $sources, $definitions);
            $this->createEdges($user, $connections);
            $this->createShareLinks($user, $connections);
            $this->bindFeatures($user, $definitions, $sources);
            $this->seedDiscord($user, $definitions, $connections);
            $this->storeKeyRing($user);
        });

        $this->command->info(sprintf('Demo account ready: login "%s", master password "%s".', self::DEMO_NAME, self::DEMO_PASSWORD));
    }

    /** A handle-style name ("mossy_fox", "NeonMoth", "amber.finch42"), unique within one run. */
    private function username(): string
    {
        $faker = fake();

        do {
            $a = $faker->randomElement(self::ADJECTIVES);
            $n = $faker->randomElement(self::NOUNS);

            $name = match ($faker->numberBetween(0, 4)) {
                0 => $a.$n,
                1 => $a.'_'.$n,
                2 => $a.$n.$faker->numberBetween(2, 99),
                3 => ucfirst($a).ucfirst($n),
                default => $a.'.'.$n,
            };
        } while (isset($this->usernames[$name]));

        $this->usernames[$name] = true;

        return $name;
    }

    private function createUser(): User
    {
        $id = (string) Str::uuid();
        $this->passphraseSalt = random_bytes(16);

        // deriveLoginVerifier(masterPassword, user.id): salt = first 16 bytes of SHA-256(lowercased, trimmed id).
        $verifier = base64_encode($this->argon2(self::DEMO_PASSWORD, substr(hash('sha256', strtolower(trim($id)), true), 0, 16)));

        $user = new User([
            'name' => self::DEMO_NAME,
            'email' => null,
            'password' => $verifier,
            'verifier_salt_version' => 'id',
            'passphrase_salt' => base64_encode($this->passphraseSalt),
            'timezone' => 'Europe/Budapest',
        ]);
        $user->id = $id;
        $user->email_verified_at = now();
        // Not mass-assignable (§0.2 tier, Crypt/APP_KEY): the dev mock feed (DevMockCalendarController), so share links
        // compute real availability. Plain http on the app's own host: the worker fetches it from this machine.
        $user->calendar_url_ciphertext = Crypt::encryptString('http://'.parse_url((string) config('app.url'), PHP_URL_HOST).'/dev/mock-calendar.ics');
        $user->save();

        return $user;
    }

    /** @return array<string, ConnectionSourceCategory> keyed by slug */
    private function createCategories(User $user): array
    {
        $out = [];
        foreach ([
            'online' => ['Online', 'violet'],
            'events' => ['Events', 'amber'],
            'personal' => ['Personal', 'teal'],
        ] as $slug => [$name, $color]) {
            $id = (string) Str::uuid();
            $out[$slug] = ConnectionSourceCategory::create([
                'id' => $id,
                'user_id' => $user->id,
                'name_ciphertext' => $this->encryptFor($id, $name),
                'color_key' => $color,
                'sort_order' => count($out),
            ]);
        }

        return $out;
    }

    /**
     * @param  array<string, ConnectionSourceCategory>  $categories
     * @return list<ConnectionSource>
     */
    private function createSources(User $user, array $categories): array
    {
        $out = [];
        foreach ([
            ['Discord server', 'online'],
            ['VRChat world meetup', 'online'],
            ['Game night group', 'online'],
            ['Winter convention', 'events'],
            ['Summer festival', 'events'],
            ['University', 'personal'],
            ['Neighbourhood', 'personal'],
            ['Uncategorised acquaintance', null],
        ] as [$name, $category]) {
            $id = (string) Str::uuid();
            $out[] = ConnectionSource::create([
                'id' => $id,
                'user_id' => $user->id,
                'category_id' => $category === null ? null : $categories[$category]->id,
                'name_ciphertext' => $this->encryptFor($id, $name),
                'sort_order' => count($out),
            ]);
        }

        return $out;
    }

    /**
     * One definition per field type, so every renderer and editor has data.
     *
     * @return array<string, ConnectionAttributeDefinition> keyed by slug
     */
    private function createDefinitions(User $user): array
    {
        $out = [];

        $spec = [
            'notes' => ['Notes', 'textarea', null],
            'pronouns' => ['Pronouns', 'text', null],
            'birthday' => ['Birthday', 'date', null],
            'country' => ['Country', 'country', null],
            'lucky' => ['Lucky number', 'number', null],
            'website' => ['Website', 'url', null],
            'email' => ['Email', 'email', null],
            'phone' => ['Phone', 'phone', null],
            'closeness' => ['Closeness', 'radio', ['Acquaintance', 'Friend', 'Close friend', 'Family']],
            'interests' => ['Interests', 'list', null],
            'links' => ['Links', 'link_list', null],
            'introduced_by' => ['Introduced by', 'connection', null],
            'handle' => ['Handle', 'identifier', null],
            'highlight' => ['Highlight words', 'identifier_list', null],
            'alt_names' => ['Other names', 'identifier_list', null],
            'bypass_dnd' => ['Bypass DND', 'boolean', null],
            // Bound in Settings (VRCX integration): where each connection keeps its VRChat / Discord accounts.
            'vrchat_id' => ['VRChat user ID', 'identifier_list', null],
            'discord_id' => ['Discord user ID', 'identifier', null],
        ];

        foreach ($spec as $slug => [$label, $type, $choices]) {
            $id = (string) Str::uuid();

            $rules = null;
            if ($slug === 'bypass_dnd') {
                $rules = ['version' => 1, 'stopAfterFirst' => false, 'rules' => [[
                    'id' => (string) Str::uuid(),
                    'icon' => 'bell',
                    'color' => 'amber',
                    'label' => 'Can reach me during Do Not Disturb',
                    'condition' => ['op' => 'isTrue'],
                ]]];
            } elseif ($slug === 'closeness') {
                $rules = ['version' => 1, 'stopAfterFirst' => true, 'rules' => [[
                    'id' => (string) Str::uuid(),
                    'icon' => 'heart',
                    'color' => 'rose',
                    'label' => 'Close friend',
                    'condition' => ['op' => 'choiceIn', 'choices' => ['Close friend', 'Family']],
                ]]];
            }

            $out[$slug] = ConnectionAttributeDefinition::create([
                'id' => $id,
                'user_id' => $user->id,
                'label_ciphertext' => $this->encryptFor($id, $label),
                'type' => $type,
                'options_ciphertext' => $choices === null ? null : $this->encryptFor($id, json_encode(['choices' => $choices], JSON_THROW_ON_ERROR)),
                'icon_rules_ciphertext' => $rules === null ? null : $this->encryptFor($id, json_encode($rules, JSON_THROW_ON_ERROR)),
                'sort_order' => count($out),
            ]);
        }

        return $out;
    }

    /**
     * @param  list<ConnectionSource>  $sources
     * @param  array<string, ConnectionAttributeDefinition>  $d
     * @return list<Connection>
     */
    private function createConnections(User $user, array $sources, array $d): array
    {
        $faker = fake();
        $interests = ['Synthwave', 'Bouldering', 'Pixel art', 'Tabletop RPGs', 'Baking', 'Cycling', 'Retro consoles', 'Birdwatching', 'Photography', 'Linux', 'Language learning', 'Hiking', 'Cosplay', 'Modular synths'];
        $countries = ['HU', 'DE', 'US', 'GB', 'JP', 'CA', 'NL', 'SE', 'FR', 'AU', 'PL'];
        $pronouns = ['she/her', 'he/him', 'they/them', 'she/they', 'he/they'];
        $noteLines = [
            'Met at the registration desk and ended up chatting for hours.',
            'Prefers messages over calls.',
            'Always up for a board game.',
            'Allergic to cats, loves dogs.',
            'Usually online late in the evening.',
            "Owes me a book recommendation.\nPromised to share their playlist.",
            'Organises the monthly meetup.',
        ];

        $connections = [];

        for ($i = 0; $i < self::CONNECTION_COUNT; $i++) {
            $id = (string) Str::uuid();
            $name = $this->username();
            $createdAt = Carbon::now()->subDays($faker->numberBetween(2, 400))->subMinutes($faker->numberBetween(0, 1400));

            $connection = Connection::create([
                'id' => $id,
                'user_id' => $user->id,
                'name_ciphertext' => $this->encryptFor($id, $name),
                'archived' => $i >= self::CONNECTION_COUNT - 3,
                'sort_order' => $i,
            ]);
            $connection->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

            // Each connection belongs to one or two sources.
            $connection->sources()->sync(collect($sources)->random($faker->numberBetween(1, 2))->pluck('id')->all());

            // Attribute values are keyed by their PARENT connection's id.
            $e2ee = fn (string $slug, string $plaintext) => ConnectionAttributeValue::create([
                'connection_id' => $id,
                'attribute_definition_id' => $d[$slug]->id,
                'value_ciphertext' => $this->encryptFor($id, $plaintext),
            ]);

            // Roughly 70% fill each optional field, so empty states show up too.
            $has = fn (int $percent = 70) => $faker->numberBetween(1, 100) <= $percent;

            if ($has(60)) {
                $e2ee('notes', $faker->randomElement($noteLines));
            }
            if ($has()) {
                $e2ee('pronouns', $faker->randomElement($pronouns));
            }
            if ($has(50)) {
                $e2ee('birthday', $faker->dateTimeBetween('-45 years', '-18 years')->format('Y-m-d'));
            }
            if ($has()) {
                $e2ee('country', $faker->randomElement($countries));
            }
            if ($has(30)) {
                $e2ee('lucky', (string) $faker->numberBetween(1, 99));
            }
            if ($has(40)) {
                $e2ee('website', 'https://'.Str::slug($name).'.example.com');
            }
            if ($has(40)) {
                $e2ee('email', Str::slug($name, '.').'@example.com');
            }
            if ($has(25)) {
                $e2ee('phone', '+36 30 '.$faker->numerify('### ####'));
            }
            if ($has(85)) {
                $e2ee('closeness', $faker->randomElement(['Acquaintance', 'Acquaintance', 'Friend', 'Friend', 'Close friend', 'Family']));
            }
            if ($has()) {
                $e2ee('interests', $this->paddedList(array_values($faker->randomElements($interests, $faker->numberBetween(1, 4)))));
            }
            if ($has(35)) {
                $e2ee('links', $this->paddedList([
                    'https://social.example.com/@'.Str::slug($name, '_'),
                    'https://gallery.example.org/'.Str::slug($name),
                ]));
            }

            // Plaintext (§0.2) types.
            if ($has(65)) {
                ConnectionAttributeValue::create([
                    'connection_id' => $id,
                    'attribute_definition_id' => $d['handle']->id,
                    'identifier_value' => Str::slug($name, '_').$faker->numberBetween(10, 99),
                ]);
            }
            if ($has(50)) {
                ConnectionAttributeValue::create([
                    'connection_id' => $id,
                    'attribute_definition_id' => $d['highlight']->id,
                    'identifier_list_value' => array_values(array_unique([$name, Str::slug($name, '')])),
                ]);
            }
            if ($has(25)) {
                ConnectionAttributeValue::create([
                    'connection_id' => $id,
                    'attribute_definition_id' => $d['alt_names']->id,
                    'identifier_list_value' => [$this->username(), $this->username()],
                ]);
            }
            if ($has(60)) {
                ConnectionAttributeValue::create([
                    'connection_id' => $id,
                    'attribute_definition_id' => $d['bypass_dnd']->id,
                    'boolean_value' => $faker->boolean(25),
                ]);
            }

            $connections[] = $connection;
        }

        // Accounts that match the mock VRCX server's users (DevMockVrchat), so a sync has real-looking data to pull:
        // the first 12 connections each hold one VRChat account (one holds two), the other mock users stay pending friends.
        foreach (array_slice($connections, 0, 12) as $i => $connection) {
            $ids = $i === 3 ? [DevMockVrchat::userId($i + 1), DevMockVrchat::userId(15)] : [DevMockVrchat::userId($i + 1)];

            ConnectionAttributeValue::create([
                'connection_id' => $connection->id,
                'attribute_definition_id' => $d['vrchat_id']->id,
                'identifier_list_value' => $ids,
            ]);

            // Time together in VRChat (what a VRCX sync would store), so the dashboard's "Time spent" has data without a sync.
            foreach ($ids as $vrchatId) {
                $n = DevMockVrchat::numberOf($vrchatId);

                foreach ($n === null ? [] : DevMockVrchat::intervals($n, 90) as $row) {
                    ConnectionVrchatStay::create([
                        'connection_id' => $connection->id,
                        'vrchat_user_id_hash' => VrchatUserId::hash($vrchatId),
                        'dedupe_hash' => VrchatUserId::hash($vrchatId.'|'.$row['start']),
                        'payload' => [
                            'vrchat_user_id' => $vrchatId,
                            'start' => $row['start'],
                            'end' => $row['end'],
                            'duration_ms' => $row['durationMs'],
                            'other_player_count' => $row['otherPlayerCount'],
                        ],
                    ]);
                }
            }

            // Some already know the Discord ID VRCX will report; the rest are filled in by the first sync.
            if (in_array($i, [2, 8], true)) {
                ConnectionAttributeValue::create([
                    'connection_id' => $connection->id,
                    'attribute_definition_id' => $d['discord_id']->id,
                    'identifier_value' => DevMockVrchat::discordId($i + 1),
                ]);
            }
        }

        // Discord IDs on connections that have no VRChat account.
        foreach ([14, 17, 20] as $i) {
            ConnectionAttributeValue::create([
                'connection_id' => $connections[$i]->id,
                'attribute_definition_id' => $d['discord_id']->id,
                'identifier_value' => (string) $faker->numberBetween(300000000000000000, 900000000000000000),
            ]);
        }

        // 'connection'-type values hold the referenced connection's id inside the ciphertext.
        foreach ($connections as $i => $connection) {
            if ($i > 0 && $faker->boolean(35)) {
                ConnectionAttributeValue::create([
                    'connection_id' => $connection->id,
                    'attribute_definition_id' => $d['introduced_by']->id,
                    'value_ciphertext' => $this->encryptFor($connection->id, $connections[$faker->numberBetween(0, $i - 1)]->id),
                ]);
            }
        }

        return $connections;
    }

    /** @param list<Connection> $connections */
    private function createEdges(User $user, array $connections): void
    {
        $faker = fake();
        $seen = [];

        // A few small friend groups plus some random cross-links, one edge per unordered pair.
        $pairs = [];
        foreach (array_chunk(array_keys($connections), 5) as $group) {
            foreach ($group as $a) {
                foreach ($group as $b) {
                    if ($a < $b && $faker->boolean(60)) {
                        $pairs[] = [$a, $b];
                    }
                }
            }
        }
        for ($n = 0; $n < 8; $n++) {
            $a = $faker->numberBetween(0, count($connections) - 1);
            $b = $faker->numberBetween(0, count($connections) - 1);
            if ($a !== $b) {
                $pairs[] = [min($a, $b), max($a, $b)];
            }
        }

        foreach ($pairs as [$a, $b]) {
            if (isset($seen["$a-$b"])) {
                continue;
            }
            $seen["$a-$b"] = true;

            ConnectionEdge::create([
                'user_id' => $user->id,
                'from_connection_id' => $connections[$a]->id,
                'to_connection_id' => $connections[$b]->id,
            ]);
        }
    }

    /** @param list<Connection> $connections */
    private function createShareLinks(User $user, array $connections): void
    {
        $faker = fake();

        foreach (array_slice($connections, 0, 10) as $connection) {
            $link = ShareLink::create([
                'user_id' => $user->id,
                'connection_id' => $connection->id,
                'highlight_token' => ShareLink::generateHighlightToken(),
            ]);

            if ($faker->boolean(60)) {
                $link->forceFill(['last_visited_at' => Carbon::now()->subHours($faker->numberBetween(1, 600))])->save();
            }
        }
    }

    /**
     * @param  array<string, ConnectionAttributeDefinition>  $d
     * @param  list<ConnectionSource>  $sources
     */
    private function bindFeatures(User $user, array $d, array $sources): void
    {
        $user->forceFill([
            'notes_field_definition_id' => $d['notes']->id,
            'highlight_identifier_definition_id' => $d['highlight']->id,
            'alternative_names_definition_id' => $d['alt_names']->id,
            'bypass_dnd_definition_id' => $d['bypass_dnd']->id,
            'vrchat_user_id_definition_id' => $d['vrchat_id']->id,
            'discord_user_id_definition_id' => $d['discord_id']->id,
            // The dev mock VRCX server (DevMockVrcxController): plain http on the app's own host, like the mock
            // calendar. New friends it reports become connections under the "VRChat world meetup" source.
            'vrcx_base_url' => 'http://'.parse_url((string) config('app.url'), PHP_URL_HOST).'/dev/mock-vrcx',
            'vrcx_api_key' => DevMockVrchat::API_KEY,
            'vrcx_auto_create_friends' => true,
            'vrcx_friends_source_id' => $sources[1]->id,
        ])->save();
    }

    /**
     * Pre-seeded Discord state (there is no mock Discord server, unlike VRCX): the account counts as connected and
     * the sync's friends/blocked rows are inserted directly. For the panel to show up locally, DISCORD_CLIENT_ID
     * must also be set in the dev environment (Infisical); a real "Sync now" fails against these dummy tokens.
     * Names are invented. Covers: plain pending friends, blocked pending ones (name-matching a connection, so the
     * suggestion shows), a blocked person who already has a connection (never offered) and a dismissed/looked-up one.
     *
     * @param  array<string, ConnectionAttributeDefinition>  $d
     * @param  list<Connection>  $connections
     */
    private function seedDiscord(User $user, array $d, array $connections): void
    {
        $user->forceFill([
            'discord_access_token' => 'demo-access-token',
            'discord_refresh_token' => 'demo-refresh-token',
            'discord_token_expires_at' => now()->addYear(),
            'discord_account_name' => 'demo_account',
            'discord_synced_at' => now()->subHour(),
        ])->save();

        $existing = array_keys($this->usernames);
        $linkedId = (string) ConnectionAttributeValue::where('connection_id', $connections[14]->id)
            ->where('attribute_definition_id', $d['discord_id']->id)->value('identifier_value');

        // [snowflake, username, global name, status, blocked]
        $rows = [
            ['500000000000000001', 'mossy_lantern', 'Mossy Lantern', DiscordFriend::PENDING, false],
            ['500000000000000002', 'quietharbor', 'Quiet Harbor', DiscordFriend::PENDING, false],
            ['500000000000000003', 'pixel.parsley', null, DiscordFriend::PENDING, false],
            // Same name as an existing connection: shows the "Matches ..." suggestion.
            ['500000000000000004', 'friend_of_'.$existing[0], $existing[0], DiscordFriend::PENDING, false],
            ['500000000000000011', 'blockedbramble', 'Blocked Bramble', DiscordFriend::PENDING, true],
            ['500000000000000012', 'saltwater_sam', 'Saltwater Sam', DiscordFriend::PENDING, true],
            ['500000000000000013', 'old_'.$existing[1], $existing[1], DiscordFriend::PENDING, true],
            // Blocked, but already a connection (its Discord ID is on connection 14): must not be offered.
            [$linkedId, 'linked_and_blocked', 'Linked And Blocked', DiscordFriend::CREATED, true],
            ['500000000000000014', 'dismissed_dusk', 'Dismissed Dusk', DiscordFriend::DISMISSED, true],
            ['500000000000000015', 'lookup_larch', 'Lookup Larch', DiscordFriend::LOOKUP, false],
        ];

        foreach ($rows as [$id, $username, $globalName, $status, $blocked]) {
            DiscordFriend::create([
                'user_id' => $user->id,
                'discord_user_id_hash' => DiscordUserId::hash($id),
                'discord_user_id' => $id,
                'username' => $username,
                'global_name' => $globalName,
                'status' => $status,
                'is_blocked' => $blocked,
            ]);
        }
    }

    /** Must run last: the ring has to hold a key for every record encrypted above. */
    private function storeKeyRing(User $user): void
    {
        $vaultKey = $this->argon2(self::DEMO_PASSWORD, $this->passphraseSalt);
        $ring = json_encode(array_map('base64_encode', $this->keyRing), JSON_THROW_ON_ERROR);

        $user->forceFill(['key_ring_ciphertext' => AesGcm::encrypt($vaultKey, $ring)])->save();
    }
}
