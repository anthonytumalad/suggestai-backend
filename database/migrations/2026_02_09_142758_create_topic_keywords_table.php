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
        Schema::create('topic_keywords', function (Blueprint $table) {
            $table->id();
            $table->foreignId('topic_id')
                  ->constrained('topics')
                  ->onDelete('cascade');
            $table->string('keyword');
            $table->integer('rank');
            $table->decimal('score', 8, 6)->nullable();
            $table->timestamps();
            $table->index(['topic_id', 'rank']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('topic_keywords');
    }
};
