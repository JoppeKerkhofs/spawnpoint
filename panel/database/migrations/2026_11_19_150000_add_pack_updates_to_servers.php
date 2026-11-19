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
            $table->boolean('pack_check')->default(false);
            $table->boolean('pack_auto_update')->default(false);
            // The newest pack version the check has seen, so each one is reported once.
            $table->string('pack_latest')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['pack_check', 'pack_auto_update', 'pack_latest']);
        });
    }
};
