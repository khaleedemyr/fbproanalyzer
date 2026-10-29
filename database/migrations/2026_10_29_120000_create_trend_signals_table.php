<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trend_signals', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint')->unique();
            $table->string('source'); // google_trends|youtube|seed
            $table->string('niche')->default('psikologi');
            $table->string('keyword');
            $table->string('title')->nullable();
            $table->text('snippet')->nullable();
            $table->text('url')->nullable();
            $table->string('format')->nullable();
            $table->unsignedBigInteger('metric_value')->default(0);
            $table->decimal('viral_score', 8, 2)->default(0);
            $table->string('region')->default('ID');
            $table->json('raw')->nullable();
            $table->timestamp('observed_at')->nullable();
            $table->timestamps();

            $table->index(['niche', 'viral_score']);
            $table->index(['source', 'observed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trend_signals');
    }
};
