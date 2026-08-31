<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\UnlocksVault;
use App\Models\Connection;
use App\Models\ConnectionAttributeDefinition;
use App\Models\ConnectionAttributeValue;
use App\Models\ConnectionEdge;
use App\Models\ConnectionSource;
use App\Models\ConnectionSourceCategory;
use App\Models\ShareLink;
use App\Models\ShareLinkWord;
use App\Models\User;
use App\Services\Crypto\AesGcm;
use App\Services\Crypto\KeyRing;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Operator CLI (see ImportShareLinkLabels's doc comment for why this exists
 * and how it respects the E2EE boundary): bulk import from a JSON or CSV
 * file. Every _ciphertext field is encrypted here, client-side from this
 * process's point of view, before it ever touches the database — see
 * CLAUDE.md's "Operator CLI" section for the exact file shapes.
 *
 * Two JSON shapes are accepted:
 *   - The simple shape: a flat array of {name, notes, source, attributes}.
 *   - The source-app export shape: {sources, attribute_definitions,
 *     connections} — see importSourceAppExport() below. Detected by the
 *     presence of a top-level "connections" key.
 */
class ImportConnections extends Command
{
    use UnlocksVault;

    protected $signature = 'wtf:connections:import {email : Owner email} {input : Path to a .json or .csv file}';

    protected $description = 'Operator CLI: bulk-import connections via the owner\'s vault (see CLAUDE.md for file shapes)';

    /** @var array<string, string> attribute label -> definition id, lazily built */
    private array $definitionIdsByLabel = [];

    /** @var array<string, string> source name -> source id, lazily built */
    private array $sourceIdsByName = [];

    /** @var array<string, string> category name -> category id, lazily built */
    private array $categoryIdsByName = [];

    /** @var array<string, string> connection name -> connection id, lazily built (source-app shape only) */
    private array $connectionIdsByName = [];

    public function handle(): int
    {
        $user = $this->findUserOrFail($this->argument('email'));

        if ($user === null) {
            return self::FAILURE;
        }

        $inputPath = $this->argument('input');

        if (! file_exists($inputPath)) {
            $this->error("Input file not found: {$inputPath}");

            return self::FAILURE;
        }

        $extension = strtolower(pathinfo($inputPath, PATHINFO_EXTENSION));

        if (! in_array($extension, ['json', 'csv'], true)) {
            $this->error('Input file must be .json or .csv.');

            return self::FAILURE;
        }

        [$vaultKey, $ring] = $this->unlockVault($user) ?? [null, null];

        if ($vaultKey === null) {
            return self::FAILURE;
        }

        $this->definitionIdsByLabel = $this->decryptLabelMap(
            $user->connectionAttributeDefinitions()->get(['id', 'label_ciphertext']),
            'label_ciphertext',
            $ring,
        );
        $this->sourceIdsByName = $this->decryptLabelMap(
            $user->connectionSources()->get(['id', 'name_ciphertext']),
            'name_ciphertext',
            $ring,
        );
        $this->categoryIdsByName = $this->decryptLabelMap(
            $user->connectionSourceCategories()->get(['id', 'name_ciphertext']),
            'name_ciphertext',
            $ring,
        );
        $this->connectionIdsByName = $this->decryptLabelMap(
            $user->connections()->get(['id', 'name_ciphertext']),
            'name_ciphertext',
            $ring,
        );

        if ($extension === 'json') {
            $decoded = json_decode(file_get_contents($inputPath), associative: true, flags: JSON_THROW_ON_ERROR);

            if (is_array($decoded) && array_key_exists('connections', $decoded)) {
                $imported = $this->importSourceAppExport($user, $ring, $decoded);
            } else {
                $imported = $this->importSimpleRows($user, $ring, $this->parseJson($inputPath));
            }
        } else {
            $imported = $this->importSimpleRows($user, $ring, $this->parseCsv($inputPath));
        }

        $this->persistRing($user, $vaultKey, $ring);

        $this->info("Imported {$imported} connection(s).");

        return self::SUCCESS;
    }

    /** @param array<int, array{name: string, notes: ?string, source: ?string, attributes: array<string, string>}> $rows */
    private function importSimpleRows(User $user, array &$ring, array $rows): int
    {
        $imported = 0;

        foreach ($rows as $row) {
            if (empty($row['name'])) {
                $this->warn('Skipping a row with no name.');

                continue;
            }

            $sourceId = null;

            if (! empty($row['source'])) {
                [$sourceId, $ring] = $this->resolveSourceId($user, $ring, $row['source'], null);
            }

            $connectionId = (string) Str::uuid();
            [$rawKey, $ring] = KeyRing::getOrCreateKey($ring, $connectionId);

            $connection = Connection::create([
                'id' => $connectionId,
                'user_id' => $user->id,
                'name_ciphertext' => AesGcm::encrypt($rawKey, $row['name']),
                'notes_ciphertext' => ! empty($row['notes']) ? AesGcm::encrypt($rawKey, $row['notes']) : null,
            ]);

            if ($sourceId !== null) {
                $connection->sources()->attach($sourceId);
            }

            foreach ($row['attributes'] ?? [] as $label => $value) {
                if ($value === null || $value === '') {
                    continue;
                }

                $definitionId = $this->definitionIdsByLabel[$label] ?? null;

                if ($definitionId === null) {
                    [$definitionId, $ring] = $this->createAttributeDefinition($user, $ring, $label, 'text', []);
                }

                ConnectionAttributeValue::create([
                    'connection_id' => $connection->id,
                    'attribute_definition_id' => $definitionId,
                    'value_ciphertext' => AesGcm::encrypt($rawKey, (string) $value),
                ]);
            }

            $imported++;
        }

        return $imported;
    }

    /**
     * Source-app export shape:
     *   sources: [{name, category}]
     *   attribute_definitions: [{label, type, options: {choices:[...]}|null, sort_order}]
     *   connections: [{name, archived, created_at, attribute_values: [{attribute_label, value}],
     *                  highlight_token_label, edges: [{type: one_way|bi_directional,
     *                  target_kind: source|connection, target_name}]}]
     *
     * Two-pass: connections (+ their source edges + attribute values) are
     * created first, since connection-to-connection edges and highlight
     * tokens need every connection's id already resolved by name.
     */
    private function importSourceAppExport(User $user, array &$ring, array $data): int
    {
        foreach ($data['sources'] ?? [] as $sourceRow) {
            $name = $sourceRow['name'] ?? null;

            if ($name === null || isset($this->sourceIdsByName[$name])) {
                continue;
            }

            $categoryId = null;
            $categoryName = $sourceRow['category'] ?? null;

            if ($categoryName !== null) {
                [$categoryId, $ring] = $this->resolveCategoryId($user, $ring, $categoryName);
            }

            $sourceId = (string) Str::uuid();
            [$rawKey, $ring] = KeyRing::getOrCreateKey($ring, $sourceId);
            ConnectionSource::create([
                'id' => $sourceId,
                'user_id' => $user->id,
                'category_id' => $categoryId,
                'name_ciphertext' => AesGcm::encrypt($rawKey, $name),
            ]);
            $this->sourceIdsByName[$name] = $sourceId;
        }

        foreach ($data['attribute_definitions'] ?? [] as $defRow) {
            $label = $defRow['label'] ?? null;

            if ($label === null || isset($this->definitionIdsByLabel[$label])) {
                continue;
            }

            $type = $this->mapAttributeType($defRow['type'] ?? 'text');
            $choices = $type === 'radio' ? ($defRow['options']['choices'] ?? []) : [];

            [$definitionId, $ring] = $this->createAttributeDefinition($user, $ring, $label, $type, $choices);
        }

        $imported = 0;
        /** @var array<int, array{connectionId: string, row: array}> $processedRows */
        $processedRows = [];

        foreach ($data['connections'] ?? [] as $row) {
            $name = $row['name'] ?? null;

            if (empty($name)) {
                $this->warn('Skipping a connection row with no name.');

                continue;
            }

            $connectionId = (string) Str::uuid();
            [$rawKey, $ring] = KeyRing::getOrCreateKey($ring, $connectionId);

            $connection = Connection::create([
                'id' => $connectionId,
                'user_id' => $user->id,
                'name_ciphertext' => AesGcm::encrypt($rawKey, $name),
                'archived' => (bool) ($row['archived'] ?? false),
            ]);

            if (! empty($row['created_at']) && ($createdAt = strtotime($row['created_at'])) !== false) {
                $connection->created_at = date('Y-m-d H:i:s', $createdAt);
                $connection->save();
            }

            $this->connectionIdsByName[$name] = $connectionId;

            $sourceIds = [];

            foreach ($row['edges'] ?? [] as $edge) {
                if (($edge['target_kind'] ?? null) !== 'source') {
                    continue;
                }

                $targetName = $edge['target_name'] ?? null;

                if ($targetName === null) {
                    continue;
                }

                if (! isset($this->sourceIdsByName[$targetName])) {
                    [$sid, $ring] = $this->resolveSourceId($user, $ring, $targetName, null);
                    $sourceIds[] = $sid;
                } else {
                    $sourceIds[] = $this->sourceIdsByName[$targetName];
                }
            }

            if ($sourceIds !== []) {
                $connection->sources()->attach(array_unique($sourceIds));
            }

            foreach ($row['attribute_values'] ?? [] as $attributeValue) {
                $label = $attributeValue['attribute_label'] ?? null;
                $value = $attributeValue['value'] ?? null;

                if ($label === null || $value === null || $value === '') {
                    continue;
                }

                $definitionId = $this->definitionIdsByLabel[$label] ?? null;

                if ($definitionId === null) {
                    [$definitionId, $ring] = $this->createAttributeDefinition($user, $ring, $label, 'text', []);
                }

                ConnectionAttributeValue::create([
                    'connection_id' => $connection->id,
                    'attribute_definition_id' => $definitionId,
                    'value_ciphertext' => AesGcm::encrypt($rawKey, (string) $value),
                ]);
            }

            $processedRows[] = ['connectionId' => $connectionId, 'row' => $row];
            $imported++;
        }

        // Second pass: connection-to-connection edges — every row's own
        // connection id is now resolvable by name, including rows earlier
        // in the same file that a later row's edge points back at.
        foreach ($processedRows as ['connectionId' => $fromId, 'row' => $row]) {
            foreach ($row['edges'] ?? [] as $edge) {
                if (($edge['target_kind'] ?? null) !== 'connection') {
                    continue;
                }

                $targetName = $edge['target_name'] ?? null;
                $toId = $targetName !== null ? ($this->connectionIdsByName[$targetName] ?? null) : null;

                if ($toId === null) {
                    $this->warn("Skipping an edge to unknown connection \"{$targetName}\".");

                    continue;
                }

                $this->createEdgeIfMissing($user, $fromId, $toId);

                if (($edge['type'] ?? null) === 'bi_directional') {
                    $this->createEdgeIfMissing($user, $toId, $fromId);
                }
            }
        }

        // A highlight token names someone worth tracking even if this
        // export's own connection list never separately defines them —
        // ensure a bare connection exists for every such name, then tie a
        // fresh share link to it (whichever connection the name resolves
        // to — the row's own, if the token label just repeats its name, or
        // the bare one just created otherwise), labeled with that same
        // name AND configured with that name as its one highlight word —
        // without it, HighlightMatcher has nothing to match against and
        // the link would never highlight a single event, silently useless
        // until the owner noticed and added a word by hand.
        foreach ($data['connections'] ?? [] as $row) {
            $tokenName = $row['highlight_token_label'] ?? null;

            if ($tokenName === null) {
                continue;
            }

            if (! isset($this->connectionIdsByName[$tokenName])) {
                $connectionId = (string) Str::uuid();
                [$rawKey, $ring] = KeyRing::getOrCreateKey($ring, $connectionId);

                Connection::create([
                    'id' => $connectionId,
                    'user_id' => $user->id,
                    'name_ciphertext' => AesGcm::encrypt($rawKey, $tokenName),
                ]);

                $this->connectionIdsByName[$tokenName] = $connectionId;
                $imported++;
            }

            $connection = Connection::find($this->connectionIdsByName[$tokenName]);

            if ($connection->share_link_id !== null) {
                continue;
            }

            $shareLinkId = (string) Str::uuid();
            [$labelKey, $ring] = KeyRing::getOrCreateKey($ring, $shareLinkId);

            ShareLink::create([
                'id' => $shareLinkId,
                'user_id' => $user->id,
                'label_ciphertext' => AesGcm::encrypt($labelKey, $tokenName),
            ]);

            ShareLinkWord::create([
                'share_link_id' => $shareLinkId,
                'word_ciphertext' => Crypt::encryptString($tokenName),
            ]);

            $connection->update(['share_link_id' => $shareLinkId]);
        }

        return $imported;
    }

    private function createEdgeIfMissing(User $user, string $fromId, string $toId): void
    {
        if ($fromId === $toId) {
            return;
        }

        $exists = ConnectionEdge::where('from_connection_id', $fromId)
            ->where('to_connection_id', $toId)
            ->exists();

        if ($exists) {
            return;
        }

        ConnectionEdge::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'from_connection_id' => $fromId,
            'to_connection_id' => $toId,
            'label_ciphertext' => null,
        ]);
    }

    /** connection_attribute_definitions.type only supports these — anything else (e.g. an unrecognized future source-app type) falls back to 'text' rather than failing the whole import. */
    private function mapAttributeType(string $type): string
    {
        return in_array($type, ['text', 'textarea', 'date', 'number', 'url', 'email', 'phone', 'radio'], true)
            ? $type
            : 'text';
    }

    /** @return array{0: string, 1: array<string, string>} [categoryId, updatedRing] */
    private function resolveCategoryId(User $user, array $ring, string $name): array
    {
        if (isset($this->categoryIdsByName[$name])) {
            return [$this->categoryIdsByName[$name], $ring];
        }

        $categoryId = (string) Str::uuid();
        [$rawKey, $ring] = KeyRing::getOrCreateKey($ring, $categoryId);

        ConnectionSourceCategory::create([
            'id' => $categoryId,
            'user_id' => $user->id,
            'name_ciphertext' => AesGcm::encrypt($rawKey, $name),
        ]);

        $this->categoryIdsByName[$name] = $categoryId;

        return [$categoryId, $ring];
    }

    /** @return array<int, array{name: string, notes: ?string, source: ?string, attributes: array<string, string>}> */
    private function parseJson(string $path): array
    {
        $data = json_decode(file_get_contents($path), associative: true, flags: JSON_THROW_ON_ERROR);

        return array_map(fn (array $row) => [
            'name' => $row['name'] ?? '',
            'notes' => $row['notes'] ?? null,
            'source' => $row['source'] ?? null,
            'attributes' => $row['attributes'] ?? [],
        ], $data);
    }

    /**
     * Header row: name,notes,source,attr:Label,attr:AnotherLabel — any
     * column prefixed `attr:` becomes an attribute keyed by the rest of
     * its header name.
     *
     * @return array<int, array{name: string, notes: ?string, source: ?string, attributes: array<string, string>}>
     */
    private function parseCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);
        $rows = [];

        while (($line = fgetcsv($handle)) !== false) {
            $row = array_combine($header, $line);
            $attributes = [];

            foreach ($row as $column => $value) {
                if (str_starts_with($column, 'attr:')) {
                    $attributes[substr($column, 5)] = $value;
                }
            }

            $rows[] = [
                'name' => $row['name'] ?? '',
                'notes' => $row['notes'] ?? null,
                'source' => $row['source'] ?? null,
                'attributes' => $attributes,
            ];
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @param  Collection<int, Model>  $models
     * @param  array<string, string>  $ring
     * @return array<string, string> plaintext label -> model id
     */
    private function decryptLabelMap($models, string $ciphertextField, array $ring): array
    {
        $map = [];

        foreach ($models as $model) {
            $key = $ring[$model->id] ?? null;

            if ($key !== null) {
                $map[AesGcm::decrypt(base64_decode($key, true), $model->$ciphertextField)] = $model->id;
            }
        }

        return $map;
    }

    /** @return array{0: string, 1: array<string, string>} [sourceId, updatedRing] */
    private function resolveSourceId(User $user, array $ring, string $name, ?string $categoryName): array
    {
        if (isset($this->sourceIdsByName[$name])) {
            return [$this->sourceIdsByName[$name], $ring];
        }

        $categoryId = null;

        if ($categoryName !== null) {
            [$categoryId, $ring] = $this->resolveCategoryId($user, $ring, $categoryName);
        }

        $sourceId = (string) Str::uuid();
        [$rawKey, $ring] = KeyRing::getOrCreateKey($ring, $sourceId);

        ConnectionSource::create([
            'id' => $sourceId,
            'user_id' => $user->id,
            'category_id' => $categoryId,
            'name_ciphertext' => AesGcm::encrypt($rawKey, $name),
        ]);

        $this->sourceIdsByName[$name] = $sourceId;

        return [$sourceId, $ring];
    }

    /**
     * @param  array<string, string>  $ring
     * @param  string[]  $choices
     * @return array{0: string, 1: array<string, string>} [definitionId, updatedRing]
     */
    private function createAttributeDefinition(User $user, array $ring, string $label, string $type, array $choices): array
    {
        $definitionId = (string) Str::uuid();
        [$rawKey, $ring] = KeyRing::getOrCreateKey($ring, $definitionId);

        ConnectionAttributeDefinition::create([
            'id' => $definitionId,
            'user_id' => $user->id,
            'label_ciphertext' => AesGcm::encrypt($rawKey, $label),
            'type' => $type,
            'options_ciphertext' => $type === 'radio' ? AesGcm::encrypt($rawKey, json_encode(['choices' => $choices])) : null,
        ]);

        $this->definitionIdsByLabel[$label] = $definitionId;

        return [$definitionId, $ring];
    }
}
