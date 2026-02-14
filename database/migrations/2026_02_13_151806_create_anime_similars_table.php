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
        Schema::create('anime_similars', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('anime_id');
            $table->unsignedBigInteger('similar_anime_id');

            $table->decimal('score', 8, 3)->default(0);
            $table->unsignedSmallInteger('position')->default(1);
            $table->string('source', 20)->default('auto'); //auto | manual

            $table->timestamps();

            $table->unique(['anime_id', 'similar_anime_id']);
            $table->index(['anime_id', 'position']);
            $table->index(['anime_id', 'score']);

            $table->foreign('anime_id')->references('id')->on('anime')->onDelete('cascade');
            $table->foreign('similar_anime_id')->references('id')->on('anime')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('anime_similars');
    }
};
