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
        Schema::create('episode_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('episode_id')
                ->constrained('episodes')
                ->cascadeOnDelete();
            $table->string('type', 20)->default('video');
            $table->string('quality', 20)->nullable();
            $table->string('path', 1024);
            $table->string('mime', 100)->nullable();
            $table->unsignedInteger('size')->nullable();
            $table->unsignedInteger('duration')->nullable();
            $table->string('language', 50)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index(['episode_id', 'type', 'quality']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('episode_media');
    }
};
