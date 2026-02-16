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
        Schema::create('topics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')
                  ->constrained('topic_modeling_sessions')
                  ->onDelete('cascade');
            $table->integer('topic_id');
            $table->string('original_name');
            $table->string('label');
            $table->string('language');
            $table->integer('document_count');
            $table->decimal('representation_score', 8, 6);
            $table->timestamps();
            $table->index(['session_id', 'topic_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('topics');
    }
};
