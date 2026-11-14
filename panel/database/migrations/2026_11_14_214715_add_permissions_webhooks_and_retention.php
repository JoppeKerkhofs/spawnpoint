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
        Schema::table('server_user', function (Blueprint $table) {
            // What a viewer may do on this server beyond seeing it; a JSON list such as ["logs","power"].
            $table->json('permissions')->nullable();
        });
        // Until now every share came with the logs, so existing shares keep them.
        DB::table('server_user')->update(['permissions' => '["logs"]']);

        Schema::table('servers', function (Blueprint $table) {
            // Delete unlocked backups older than this many days; null for no age limit.
            $table->unsignedSmallInteger('backup_retention_days')->nullable();
        });

        Schema::create('webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Encrypted: a Discord or Slack webhook address is itself the secret.
            $table->text('url');
            $table->string('format');
            $table->json('events');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('last_status')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhooks');

        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('backup_retention_days');
        });

        Schema::table('server_user', function (Blueprint $table) {
            $table->dropColumn('permissions');
        });
    }
};
