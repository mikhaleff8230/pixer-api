<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // The original migration is idempotent and safely creates only missing
        // tables/columns. Re-run it under a new migration ID to recover from a
        // previously interrupted production migration.
        $migration = require __DIR__.'/2026_08_18_000001_create_yandex_direct_boost_tables.php';
        $migration->up();
    }

    public function down(): void
    {
        // Recovery migration must never remove structures owned by 000001.
    }
};
