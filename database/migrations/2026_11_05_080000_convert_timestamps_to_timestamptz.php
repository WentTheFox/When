<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Converts every `timestamp without time zone` column in the current
     * schema to `timestamptz`. The app has always written values in
     * app.timezone (UTC), so existing naive values are interpreted as UTC.
     * Discovered from information_schema rather than listed by hand so no
     * column is missed; columns that are already timestamptz are untouched.
     */
    public function up(): void
    {
        $this->convert('timestamp without time zone', 'timestamptz');
    }

    public function down(): void
    {
        $this->convert('timestamp with time zone', 'timestamp');
    }

    private function convert(string $from, string $to): void
    {
        $columns = DB::select(
            "SELECT c.table_name, c.column_name FROM information_schema.columns c
             JOIN information_schema.tables t ON t.table_schema = c.table_schema AND t.table_name = c.table_name
             WHERE c.table_schema = current_schema() AND t.table_type = 'BASE TABLE' AND c.data_type = ?
             ORDER BY c.table_name, c.ordinal_position",
            [$from],
        );

        foreach ($columns as $column) {
            $table = '"'.$column->table_name.'"';
            $name = '"'.$column->column_name.'"';
            DB::statement("ALTER TABLE {$table} ALTER COLUMN {$name} TYPE {$to} USING {$name} AT TIME ZONE 'UTC'");
        }
    }
};
