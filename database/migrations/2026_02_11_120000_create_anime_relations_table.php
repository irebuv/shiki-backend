<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anime_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('anime_id')
                ->constrained('anime')
                ->cascadeOnDelete();
            $table->foreignId('related_anime_id')
                ->constrained('anime')
                ->cascadeOnDelete();
            $table->string('relation_type', 32);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(
                ['anime_id', 'related_anime_id', 'relation_type'],
                'anime_relations_unique',
            );
            $table->index(
                ['anime_id', 'relation_type', 'sort_order'],
                'anime_relations_sort_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anime_relations');
    }
};

