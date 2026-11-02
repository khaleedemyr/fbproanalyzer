<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_kits', function (Blueprint $table) {
            $table->boolean('is_active')->default(false)->after('video_tool');
            $table->string('region', 8)->default('ID')->after('is_active');
            $table->index(['user_id', 'is_active']);
        });

        // Mark one kit per user as active (latest id).
        $userIds = DB::table('brand_kits')->distinct()->pluck('user_id');
        foreach ($userIds as $userId) {
            $kitId = DB::table('brand_kits')
                ->where('user_id', $userId)
                ->orderByDesc('id')
                ->value('id');
            if ($kitId) {
                DB::table('brand_kits')->where('id', $kitId)->update(['is_active' => true]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('brand_kits', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'is_active']);
            $table->dropColumn(['is_active', 'region']);
        });
    }
};
