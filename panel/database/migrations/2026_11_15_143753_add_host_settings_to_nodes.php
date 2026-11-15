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
        Schema::table('nodes', function (Blueprint $table) {
            // Each node is its own host, so these differ per node; null follows the panel's settings.
            $table->string('dns_target')->nullable();
            $table->string('bluemap_url')->nullable();
            $table->unsignedInteger('memory_limit_mb')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn(['dns_target', 'bluemap_url', 'memory_limit_mb']);
        });
    }
};
