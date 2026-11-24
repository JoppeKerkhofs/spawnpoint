<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_pairings', function (Blueprint $table): void {
            // What the app asked for by itself; null for a pairing approved with the picker.
            $table->json('requested_abilities')->nullable()->after('device_name');
        });
    }

    public function down(): void
    {
        Schema::table('device_pairings', function (Blueprint $table): void {
            $table->dropColumn('requested_abilities');
        });
    }
};
