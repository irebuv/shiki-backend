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
        Schema::create('anime_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('anime_id')->constrained('anime')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // One-level nesting: parent_id points to root comment only
            $table->foreignId('parent_id')->nullable()->constrained('anime_comments')->cascadeOnDelete();
            $table->foreignId('reply_to_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->text('body')->nullable();
            $table->boolean('has_spoiler')->default(false);

            $table->boolean('is_edited')->default(false);
            $table->timestamp('edited_at')->nullable();

            $table->unsignedInteger('likes_count')->default(0);
            $table->unsignedInteger('dislikes_count')->default(0);
            $table->unsignedInteger('replies_count')->default(0);

            $table->enum('status', ['active', 'deleted', 'hidden'])->default('active');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['anime_id', 'parent_id', 'created_at']);
            $table->index(['parent_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('anime_comments');
    }
};
