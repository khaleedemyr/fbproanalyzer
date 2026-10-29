<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_blueprints', function (Blueprint $table) {
            $table->string('format', 40)->default('reels_vo')->after('status');
            $table->json('title_options')->nullable()->after('caption');
        });
    }

    public function down(): void
    {
        Schema::table('content_blueprints', function (Blueprint $table) {
            $table->dropColumn(['format', 'title_options']);
        });
    }
};
