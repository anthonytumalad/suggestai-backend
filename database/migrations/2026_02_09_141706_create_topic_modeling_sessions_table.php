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
        Schema::create('topic_modeling_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('source_type')->default('suggestions');
            $table->integer('total_topics');
            $table->integer('total_documents');
            $table->integer('outliers')->default(0);
            $table->json('model_parameters')->nullable();
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('topic_modeling_sessions');
    }
};
