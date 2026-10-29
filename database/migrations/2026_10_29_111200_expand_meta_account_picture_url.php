<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE users MODIFY avatar TEXT NULL');
            DB::statement('ALTER TABLE meta_accounts MODIFY picture_url TEXT NULL');
            DB::statement('ALTER TABLE content_posts MODIFY permalink TEXT NULL');
            DB::statement('ALTER TABLE content_posts MODIFY media_url TEXT NULL');

            return;
        }

        if ($driver === 'sqlite') {
            // SQLite ignores VARCHAR length; nothing critical to change.
            return;
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE users MODIFY avatar VARCHAR(255) NULL');
            DB::statement('ALTER TABLE meta_accounts MODIFY picture_url VARCHAR(255) NULL');
            DB::statement('ALTER TABLE content_posts MODIFY permalink VARCHAR(255) NULL');
            DB::statement('ALTER TABLE content_posts MODIFY media_url VARCHAR(255) NULL');
        }
    }
};
