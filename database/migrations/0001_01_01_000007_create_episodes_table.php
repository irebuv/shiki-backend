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
        Schema::create('episodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('anime_id')
                ->constrained('anime')
                ->cascadeOnDelete();
            $table->unsignedSmallInteger('season_number')->default(1);
            $table->unsignedSmallInteger('episode_number');
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('duration')->nullable();
            $table->date('air_date')->nullable();
            $table->timestamps();

            $table->unique(['anime_id', 'season_number', 'episode_number']);
            $table->index(['anime_id', 'season_number', 'episode_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('episodes');
    }
};
