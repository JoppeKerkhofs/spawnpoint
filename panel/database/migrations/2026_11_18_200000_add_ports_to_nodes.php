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
            // The ports the host publishes for players and for the web maps; null is 25565 and 8100.
            $table->unsignedSmallInteger('minecraft_port')->nullable();
            $table->unsignedSmallInteger('maps_port')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn(['minecraft_port', 'maps_port']);
        });
    }
};
