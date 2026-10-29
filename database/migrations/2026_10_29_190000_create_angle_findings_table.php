<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('angle_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('topic', 180);
            $table->string('engine', 40)->default('template');
            $table->unsignedInteger('videos_scanned')->default(0);
            $table->unsignedInteger('comments_scanned')->default(0);
            $table->json('source_videos')->nullable();
            $table->json('pain_points')->nullable();
            $table->json('angles')->nullable();
            $table->json('sample_comments')->nullable();
            $table->text('summary')->nullable();
            $table->text('warning')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('angle_findings');
    }
};
