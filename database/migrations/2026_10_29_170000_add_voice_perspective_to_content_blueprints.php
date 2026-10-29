<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_blueprints', function (Blueprint $table) {
            $table->string('voice_perspective', 40)->default('Aku')->after('prefer_stock');
        });
    }

    public function down(): void
    {
        Schema::table('content_blueprints', function (Blueprint $table) {
            $table->dropColumn('voice_perspective');
        });
    }
};
