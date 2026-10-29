<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar')->nullable()->after('email');
            $table->string('meta_user_id')->nullable()->unique()->after('avatar');
        });

        Schema::create('meta_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('meta_id');
            $table->string('name');
            $table->string('username')->nullable();
            $table->string('type')->default('page'); // page|profile
            $table->string('niche')->default('psikologi');
            $table->string('category')->nullable();
            $table->string('picture_url')->nullable();
            $table->text('access_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'meta_id']);
        });

        Schema::create('content_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meta_account_id')->constrained()->cascadeOnDelete();
            $table->string('meta_post_id')->unique();
            $table->string('permalink')->nullable();
            $table->string('format')->default('post'); // reel|photo|video|carousel|status|link
            $table->text('message')->nullable();
            $table->string('media_url')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('reach')->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('likes')->default(0);
            $table->unsignedBigInteger('comments')->default(0);
            $table->unsignedBigInteger('shares')->default(0);
            $table->unsignedBigInteger('saves')->default(0);
            $table->decimal('engagement_rate', 8, 4)->default(0);
            $table->decimal('viral_score', 8, 2)->default(0);
            $table->string('topic_cluster')->nullable();
            $table->json('raw_insights')->nullable();
            $table->timestamps();

            $table->index(['meta_account_id', 'published_at']);
            $table->index(['user_id', 'viral_score']);
        });

        Schema::create('watchlist_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('facebook_url')->nullable();
            $table->string('meta_id')->nullable();
            $table->string('niche')->default('psikologi');
            $table->string('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('watchlist_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('watchlist_account_id')->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->text('caption')->nullable();
            $table->string('post_url')->nullable();
            $table->string('format')->default('reel');
            $table->unsignedBigInteger('likes')->default(0);
            $table->unsignedBigInteger('comments')->default(0);
            $table->unsignedBigInteger('shares')->default(0);
            $table->decimal('estimated_score', 8, 2)->default(0);
            $table->string('hook_note')->nullable();
            $table->timestamp('observed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('content_ideas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meta_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('angle')->nullable();
            $table->string('format')->default('reel');
            $table->string('hook')->nullable();
            $table->string('topic_cluster')->nullable();
            $table->decimal('potential_score', 8, 2)->default(0);
            $table->string('status')->default('queued'); // queued|in_progress|published|rejected
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_ideas');
        Schema::dropIfExists('watchlist_posts');
        Schema::dropIfExists('watchlist_accounts');
        Schema::dropIfExists('content_posts');
        Schema::dropIfExists('meta_accounts');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['avatar', 'meta_user_id']);
        });
    }
};
