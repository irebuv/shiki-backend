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
        Schema::table('anime', function (Blueprint $table) {
            $table->string('type', 50)->nullable()->after('rating');
            $table->unsignedSmallInteger('episodes')->nullable()->after('type');
            $table->unsignedSmallInteger('episode_time')->nullable()->after('episodes');
            $table->date('release_date')->nullable()->after('episode_time');
            $table->string('status', 50)->nullable()->after('release_date');
            $table->string('age_rating', 20)->nullable()->after('status');
            $table->string('studio')->nullable()->after('age_rating');
            $table->text('related')->nullable()->after('studio');
            $table->text('authors')->nullable()->after('related');
            $table->text('main_characters')->nullable()->after('authors');
            $table->text('similar')->nullable()->after('main_characters');
            $table->text('reviews')->nullable()->after('similar');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('anime', function (Blueprint $table) {
            $table->dropColumn([
                'type',
                'episodes',
                'episode_time',
                'release_date',
                'status',
                'age_rating',
                'studio',
                'related',
                'authors',
                'main_characters',
                'similar',
                'reviews',
            ]);
        });
    }
};
