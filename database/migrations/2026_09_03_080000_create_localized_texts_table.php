<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Replaces the two JSON-blob columns just introduced
     * (users.public_page_title, activity_roles.label) with a proper
     * relational table — one row per (owner, field, locale), not a
     * single JSON object per record. A polymorphic `localizable`
     * relation (see App\Models\Translation / App\Concerns\
     * HasLocalizedFields) rather than two near-identical tables, since
     * both use cases are the exact same shape: some field on some model
     * that needs a `default` value plus any number of locale overrides.
     * `field` exists so a model could have more than one localized
     * column later (neither User nor ActivityRole does today) without
     * a schema change.
     */
    public function up(): void
    {
        Schema::create('localized_texts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuidMorphs('localizable');
            $table->string('field');
            // 'default' is a real locale-ish key here too (not the
            // string "en") — see App\Support\LocalizedText's own doc
            // comment for why: it's "shown when there's no override for
            // the viewer's own locale", not tied to any one language.
            $table->string('locale');
            $table->string('text');
            $table->timestamps();

            $table->unique(['localizable_type', 'localizable_id', 'field', 'locale']);
        });

        $now = now();
        $rows = [];

        foreach (DB::table('users')->whereNotNull('public_page_title')->get(['id', 'public_page_title']) as $user) {
            foreach (json_decode($user->public_page_title, true) as $locale => $text) {
                if ($text === null || $text === '') {
                    continue;
                }
                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'localizable_type' => 'App\\Models\\User',
                    'localizable_id' => $user->id,
                    'field' => 'public_page_title',
                    'locale' => $locale,
                    'text' => $text,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (DB::table('activity_roles')->get(['id', 'label']) as $role) {
            foreach (json_decode($role->label, true) as $locale => $text) {
                if ($text === null || $text === '') {
                    continue;
                }
                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'localizable_type' => 'App\\Models\\ActivityRole',
                    'localizable_id' => $role->id,
                    'field' => 'label',
                    'locale' => $locale,
                    'text' => $text,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('localized_texts')->insert($chunk);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('public_page_title');
        });
        Schema::table('activity_roles', function (Blueprint $table) {
            $table->dropColumn('label');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->jsonb('public_page_title')->nullable();
        });
        Schema::table('activity_roles', function (Blueprint $table) {
            $table->jsonb('label')->nullable();
        });

        foreach (DB::table('localized_texts')->where('field', 'public_page_title')->get() as $row) {
            $existing = DB::table('users')->where('id', $row->localizable_id)->value('public_page_title');
            $decoded = $existing ? json_decode($existing, true) : [];
            $decoded[$row->locale] = $row->text;
            DB::table('users')->where('id', $row->localizable_id)->update(['public_page_title' => json_encode($decoded)]);
        }

        foreach (DB::table('localized_texts')->where('field', 'label')->get() as $row) {
            $existing = DB::table('activity_roles')->where('id', $row->localizable_id)->value('label');
            $decoded = $existing ? json_decode($existing, true) : [];
            $decoded[$row->locale] = $row->text;
            DB::table('activity_roles')->where('id', $row->localizable_id)->update(['label' => json_encode($decoded)]);
        }

        Schema::dropIfExists('localized_texts');
    }
};
