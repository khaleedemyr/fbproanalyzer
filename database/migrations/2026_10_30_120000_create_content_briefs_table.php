<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_briefs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 180)->nullable();
            $table->string('status', 30)->default('ready'); // draft|ready|used
            $table->string('engine', 40)->default('template');
            $table->string('source_topic', 255)->nullable();
            $table->json('trend_signal_ids')->nullable();
            $table->foreignId('angle_finding_id')->nullable()->constrained('angle_findings')->nullOnDelete();
            $table->json('angle_indexes')->nullable();
            $table->foreignId('analytics_ai_analysis_id')->nullable()->constrained('analytics_ai_analyses')->nullOnDelete();
            $table->json('inputs_snapshot')->nullable();
            $table->json('resume')->nullable();
            $table->text('resume_markdown')->nullable();
            $table->foreignId('content_blueprint_id')->nullable()->constrained('content_blueprints')->nullOnDelete();
            $table->text('warning')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        Schema::table('content_blueprints', function (Blueprint $table) {
            $table->foreignId('content_brief_id')
                ->nullable()
                ->after('analytics_ai_analysis_id')
                ->constrained('content_briefs')
                ->nullOnDelete();
            $table->foreignId('angle_finding_id')
                ->nullable()
                ->after('content_brief_id')
                ->constrained('angle_findings')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('content_blueprints', function (Blueprint $table) {
            $table->dropConstrainedForeignId('angle_finding_id');
            $table->dropConstrainedForeignId('content_brief_id');
        });

        Schema::dropIfExists('content_briefs');
    }
};
