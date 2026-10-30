<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_blueprints', function (Blueprint $table) {
            $table->unsignedTinyInteger('vo_beat_seconds')->default(5)->after('beat_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('content_blueprints', function (Blueprint $table) {
            $table->dropColumn('vo_beat_seconds');
        });
    }
};
