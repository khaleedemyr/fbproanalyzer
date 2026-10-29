<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_blueprints', function (Blueprint $table) {
            $table->string('engine', 20)->default('template')->after('format');
        });
    }

    public function down(): void
    {
        Schema::table('content_blueprints', function (Blueprint $table) {
            $table->dropColumn('engine');
        });
    }
};
