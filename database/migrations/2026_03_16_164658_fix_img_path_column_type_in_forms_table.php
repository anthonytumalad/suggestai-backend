<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE forms ALTER COLUMN img_path TYPE varchar(255) USING img_path::text');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE forms ALTER COLUMN img_path TYPE bytea USING img_path::bytea');
    }
};
