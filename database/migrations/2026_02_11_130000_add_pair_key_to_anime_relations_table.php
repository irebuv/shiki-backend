<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('anime_relations', 'pair_key')) {
            Schema::table('anime_relations', function (Blueprint $table) {
                $table->string('pair_key', 36)->nullable()->after('sort_order');
                $table->index('pair_key', 'anime_relations_pair_key_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('anime_relations', 'pair_key')) {
            Schema::table('anime_relations', function (Blueprint $table) {
                $table->dropIndex('anime_relations_pair_key_idx');
                $table->dropColumn('pair_key');
            });
        }
    }
};
