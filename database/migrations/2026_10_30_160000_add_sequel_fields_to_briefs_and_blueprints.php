<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_briefs', function (Blueprint $table) {
            $table->string('series_title', 180)->nullable()->after('source_topic');
            $table->unsignedTinyInteger('episode_number')->nullable()->after('series_title');
            $table->foreignId('parent_blueprint_id')
                ->nullable()
                ->after('episode_number')
                ->constrained('content_blueprints')
                ->nullOnDelete();
            $table->json('previous_episodes')->nullable()->after('parent_blueprint_id');
        });

        Schema::table('content_blueprints', function (Blueprint $table) {
            $table->string('series_title', 180)->nullable()->after('source_topic');
            $table->unsignedTinyInteger('episode_number')->nullable()->after('series_title');
            $table->foreignId('parent_blueprint_id')
                ->nullable()
                ->after('episode_number')
                ->constrained('content_blueprints')
                ->nullOnDelete();
            $table->json('previous_episodes')->nullable()->after('parent_blueprint_id');
        });
    }

    public function down(): void
    {
        Schema::table('content_briefs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_blueprint_id');
            $table->dropColumn(['series_title', 'episode_number', 'previous_episodes']);
        });

        Schema::table('content_blueprints', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_blueprint_id');
            $table->dropColumn(['series_title', 'episode_number', 'previous_episodes']);
        });
    }
};
