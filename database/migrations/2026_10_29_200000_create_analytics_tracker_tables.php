<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_posts', function (Blueprint $table) {
            $table->unsignedBigInteger('views')->default(0)->after('impressions');
            $table->decimal('avg_watch_seconds', 10, 2)->default(0)->after('saves');
            $table->decimal('retention_rate', 8, 2)->default(0)->after('avg_watch_seconds');
            $table->string('formula_key', 120)->nullable()->after('topic_cluster');
        });

        Schema::create('analytics_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('content_post_id')->nullable()->constrained()->nullOnDelete();
            $table->string('platform', 20)->default('meta'); // meta|youtube
            $table->string('external_id', 120);
            $table->date('snapshot_date');
            $table->unsignedBigInteger('views')->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('reach')->default(0);
            $table->unsignedBigInteger('likes')->default(0);
            $table->unsignedBigInteger('comments')->default(0);
            $table->unsignedBigInteger('shares')->default(0);
            $table->unsignedBigInteger('saves')->default(0);
            $table->decimal('avg_watch_seconds', 10, 2)->default(0);
            $table->decimal('retention_rate', 8, 2)->default(0);
            $table->decimal('engagement_rate', 8, 4)->default(0);
            $table->decimal('viral_score', 8, 2)->default(0);
            $table->bigInteger('delta_views')->default(0);
            $table->bigInteger('delta_shares')->default(0);
            $table->string('format', 40)->nullable();
            $table->string('topic_cluster', 80)->nullable();
            $table->string('formula_key', 120)->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->unique(['platform', 'external_id', 'snapshot_date'], 'analytics_snap_unique');
            $table->index(['user_id', 'snapshot_date']);
            $table->index(['user_id', 'formula_key']);
        });

        Schema::create('tracked_youtube_videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('video_id', 40);
            $table->string('title')->nullable();
            $table->string('channel')->nullable();
            $table->text('url')->nullable();
            $table->string('formula_tag', 120)->nullable();
            $table->string('format', 40)->default('video');
            $table->unsignedBigInteger('views')->default(0);
            $table->unsignedBigInteger('likes')->default(0);
            $table->unsignedBigInteger('comments')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'video_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracked_youtube_videos');
        Schema::dropIfExists('analytics_snapshots');

        Schema::table('content_posts', function (Blueprint $table) {
            $table->dropColumn(['views', 'avg_watch_seconds', 'retention_rate', 'formula_key']);
        });
    }
};
