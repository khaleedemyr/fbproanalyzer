<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_ai_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('engine', 40)->default('template');
            $table->string('title', 180)->nullable();
            $table->text('diagnosis')->nullable();
            $table->json('whats_working')->nullable();
            $table->json('whats_blocking')->nullable();
            $table->json('million_view_playbook')->nullable();
            $table->json('next_7_days')->nullable();
            $table->json('title_hooks')->nullable();
            $table->json('avoid')->nullable();
            $table->json('snapshot_meta')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        Schema::table('content_blueprints', function (Blueprint $table) {
            $table->foreignId('analytics_ai_analysis_id')
                ->nullable()
                ->after('trend_signal_id')
                ->constrained('analytics_ai_analyses')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('content_blueprints', function (Blueprint $table) {
            $table->dropConstrainedForeignId('analytics_ai_analysis_id');
        });

        Schema::dropIfExists('analytics_ai_analyses');
    }
};
