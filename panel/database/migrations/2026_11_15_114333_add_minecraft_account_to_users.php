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
        Schema::table('users', function (Blueprint $table) {
            // The Minecraft account a user says is theirs: the name as Mojang spells it, and its id without dashes.
            $table->string('minecraft_name', 16)->nullable();
            $table->string('minecraft_uuid', 32)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['minecraft_name', 'minecraft_uuid']);
        });
    }
};
