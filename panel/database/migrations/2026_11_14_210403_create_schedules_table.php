<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('action');
            // HH:MM in the app timezone.
            $table->string('time', 5);
            // 0 is Sunday; null means every day.
            $table->unsignedTinyInteger('weekday')->nullable();
            $table->string('command', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['time', 'is_active']);
        });

        // The single daily backup time becomes an ordinary schedule.
        foreach (DB::table('servers')->whereNotNull('backup_at')->get(['id', 'backup_at']) as $server) {
            DB::table('schedules')->insert([
                'server_id' => $server->id, 'action' => 'backup', 'time' => $server->backup_at,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('backup_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->string('backup_at', 5)->nullable();
        });

        Schema::dropIfExists('schedules');
    }
};
