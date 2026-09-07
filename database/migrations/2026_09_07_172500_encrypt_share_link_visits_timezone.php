<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('share_link_visits', function (Blueprint $table) {
            $table->text('timezone')->change();
        });

        foreach (DB::table('share_link_visits')->select('id', 'timezone')->get() as $visit) {
            DB::table('share_link_visits')->where('id', $visit->id)->update([
                'timezone' => Crypt::encryptString($visit->timezone),
            ]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('timezone')->select('id', 'timezone')->get() as $visit) {
            DB::table('timezone')->where('id', $visit->id)->update([
                'timezone' => Crypt::decryptString($visit->timezone),
            ]);
        }

        Schema::table('timezone', function (Blueprint $table) {
            $table->date('timezone')->change();
        });
    }
};
