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
        Schema::create('anime', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->decimal('rating', 10, 2)->nullable();
            $table->string('featured_image')->nullable();
            $table->text('files')->nullable();
            $table->string('featured_image_original_name')->nullable();
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('image')->nullable();
            $table->timestamps();
        });

        Schema::create('filter_groups', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('filters', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->boolean('visible')->nullable();
            $table->foreignId('filter_group_id')
                ->constrained('filter_groups');
            $table->timestamps();
        });

        Schema::create('filter_anime', function (Blueprint $table) {
            $table->foreignId('filter_id')
                ->constrained('filters');
            $table->foreignId('anime_id')
                ->constrained('anime');
            $table->foreignId('filter_group_id')
                ->constrained('filter_groups');

            $table->primary(['filter_id', 'anime_id']);
            $table->index('anime_id');
            $table->index('filter_group_id');
            $table->index('filter_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('filter_anime');
        Schema::dropIfExists('filters');
        Schema::dropIfExists('filter_groups');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('anime');
    }
};
