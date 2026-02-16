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
        Schema::create('document_topics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('suggestion_id')
                  ->constrained('suggestions')
                  ->onDelete('cascade');
            $table->foreignId('topic_id')
                  ->constrained('topics')
                  ->onDelete('cascade');
            $table->decimal('probability', 5, 4)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->unique(['suggestion_id', 'topic_id']);
            $table->index('is_primary');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_topics');
    }
};
