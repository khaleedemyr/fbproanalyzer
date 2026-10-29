<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('youtube_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('google_user_id')->nullable();
            $table->string('channel_id')->nullable();
            $table->string('channel_title')->nullable();
            $table->string('email')->nullable();
            $table->text('picture_url')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->text('scopes')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'channel_id']);
            $table->index('user_id');
        });

        Schema::table('tracked_youtube_videos', function (Blueprint $table) {
            $table->decimal('avg_watch_seconds', 10, 2)->default(0)->after('comments');
            $table->decimal('retention_rate', 8, 2)->default(0)->after('avg_watch_seconds');
            $table->unsignedBigInteger('estimated_minutes_watched')->default(0)->after('retention_rate');
        });
    }

    public function down(): void
    {
        Schema::table('tracked_youtube_videos', function (Blueprint $table) {
            $table->dropColumn(['avg_watch_seconds', 'retention_rate', 'estimated_minutes_watched']);
        });

        Schema::dropIfExists('youtube_accounts');
    }
};
