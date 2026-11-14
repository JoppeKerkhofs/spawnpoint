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
        Schema::table('servers', function (Blueprint $table) {
            $table->boolean('bluemap_enabled')->default(false);
            // Time of the daily backup as HH:MM in the app timezone; null for none.
            $table->string('backup_at', 5)->nullable();
            $table->unsignedSmallInteger('backup_keep')->default(14);
        });

        Schema::create('server_user', function (Blueprint $table) {
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['server_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('server_user');

        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['bluemap_enabled', 'backup_at', 'backup_keep']);
        });
    }
};
