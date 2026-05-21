<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->boolean('enable_proxy')->default(false)->after('probe_enabled');
            $table->foreignId('stream_profile_id')
                ->nullable()
                ->after('enable_proxy')
                ->constrained('stream_profiles')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropForeign(['stream_profile_id']);
            $table->dropColumn(['enable_proxy', 'stream_profile_id']);
        });
    }
};
