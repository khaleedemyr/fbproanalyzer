<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_kits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name')->default('KEA Abbasy');
            $table->string('niche')->default('psikologi');
            $table->string('character_name')->default('Stickman Executive');
            $table->text('character_visual')->nullable();
            $table->text('visual_style')->nullable();
            $table->string('aspect_ratio')->default('9:16');
            $table->string('on_screen_font')->default('Anton');
            $table->string('voice_perspective')->default('Aku');
            $table->text('caption_hashtags')->nullable();
            $table->string('image_tool')->default('Leonardo AI');
            $table->string('video_tool')->default('Image-to-Video');
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });

        Schema::create('content_blueprints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_kit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('content_idea_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('trend_signal_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('cover_theme')->nullable();
            $table->text('story_concept')->nullable();
            $table->string('source_topic')->nullable();
            $table->string('status')->default('draft'); // draft|ready|scheduled|published
            $table->json('scenes')->nullable();
            $table->text('thumbnail_prompt')->nullable();
            $table->text('caption')->nullable();
            $table->date('planned_date')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'planned_date']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_blueprints');
        Schema::dropIfExists('brand_kits');
    }
};
