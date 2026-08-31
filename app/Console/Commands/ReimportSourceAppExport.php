<?php

namespace App\Console\Commands;

use App\Models\Connection;
use App\Models\ConnectionAttributeDefinition;
use App\Models\ConnectionSource;
use App\Models\ConnectionSourceCategory;
use App\Models\ShareLink;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Operator CLI: one-shot wipe-and-rebuild of a user's entire Connections
 * CRM + share links from a fresh pair of source-app exports — connections.json
 * (ConnectionsController::exportConnections()) and highlights.json
 * (DashboardController::exportHighlights()). Exists because running
 * wtf:connections:import and wtf:import-legacy-share-links against the
 * *same* data repeatedly (re-exported files, a corrected export, etc.) has
 * no clean "start over" path otherwise — wtf:connections:import has no
 * dedupe-by-name check (a second run duplicates every connection), and
 * re-running either command against already-imported data just piles up
 * more of the same rather than replacing it. This command sidesteps all of
 * that by deleting everything first, so both underlying imports always run
 * against a genuinely empty slate.
 *
 * Deletes (for this user only, nothing else touched): every Connection,
 * ConnectionSource, ConnectionSourceCategory, ConnectionAttributeDefinition,
 * and ShareLink row — DB-level cascades (see their migrations'
 * ->cascadeOnDelete()) take care of every join/child table (edges,
 * attribute values, source links, share_link_words, share_link_cache) and
 * null out the few nullable FKs elsewhere (Invite::source_share_link_id)
 * automatically. Irreversible — confirms before doing anything unless
 * --force is passed.
 *
 * Delegates the actual import work to the two existing commands
 * (wtf:connections:import then wtf:import-legacy-share-links, in that
 * order — connections must exist before the highlights import can tie
 * itself to any of them by name) via $this->call(), which shares this
 * command's own input/output, so their normal interactive vault-passphrase
 * prompts still work exactly as if run directly. Each prompts separately
 * (once per underlying command) rather than sharing one derived key across
 * both — an acceptable cost for how rarely this command runs.
 */
class ReimportSourceAppExport extends Command
{
    protected $signature = 'wtf:connections:reimport
        {email : Owner email}
        {connections : Path to the source app\'s connections.json export}
        {highlights : Path to the source app\'s highlights.json export}
        {--force : Skip the "this deletes everything" confirmation prompt}';

    protected $description = 'Operator CLI: wipe and rebuild a user\'s Connections CRM + share links from a fresh source-app export pair, in one step';

    public function handle(): int
    {
        $user = User::whereEmail($this->argument('email'))->first();

        if ($user === null) {
            $this->error("No user found for {$this->argument('email')}.");

            return self::FAILURE;
        }

        foreach (['connections', 'highlights'] as $argument) {
            if (! file_exists($this->argument($argument))) {
                $this->error("Input file not found: {$this->argument($argument)}");

                return self::FAILURE;
            }
        }

        if (! $this->option('force') && ! $this->confirm(
            "This PERMANENTLY DELETES ALL of {$user->email}'s connections, sources, categories, ".
            'attribute definitions, edges, and share links, then rebuilds them from the two files given. '.
            'This cannot be undone. Continue?'
        )) {
            $this->warn('Aborted — nothing was changed.');

            return self::FAILURE;
        }

        $this->wipe($user);
        $this->info("Wiped {$user->email}'s existing Connections CRM and share links.");

        $connectionsExit = $this->call('wtf:connections:import', [
            'email' => $user->email,
            'input' => $this->argument('connections'),
        ]);

        if ($connectionsExit !== self::SUCCESS) {
            $this->error('Connections import failed — stopping before importing highlights.');

            return self::FAILURE;
        }

        return $this->call('wtf:import-legacy-share-links', [
            'email' => $user->email,
            'input' => $this->argument('highlights'),
        ]);
    }

    private function wipe(User $user): void
    {
        DB::transaction(function () use ($user) {
            ShareLink::where('user_id', $user->id)->delete();
            Connection::where('user_id', $user->id)->delete();
            ConnectionSource::where('user_id', $user->id)->delete();
            ConnectionSourceCategory::where('user_id', $user->id)->delete();
            ConnectionAttributeDefinition::where('user_id', $user->id)->delete();
        });
    }
}
