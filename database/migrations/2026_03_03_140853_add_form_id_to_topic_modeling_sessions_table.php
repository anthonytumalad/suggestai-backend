<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('topic_modeling_sessions', function (Blueprint $table) {
            $table->foreignId('form_id')
              ->nullable()
              ->constrained('forms')
              ->nullOnDelete()
              ->after('id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('topic_modeling_sessions', function (Blueprint $table) {
            $table->dropForeignIdFor(\App\Models\Form::class);
            $table->dropColumn('form_id');
        });
    }
};
