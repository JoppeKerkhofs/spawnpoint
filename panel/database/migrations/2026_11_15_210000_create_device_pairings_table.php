<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_pairings', function (Blueprint $table): void {
            $table->id();
            // Typed by a person in the panel, so short and without look-alike characters.
            $table->string('code', 8)->unique();
            $table->string('secret_hash');
            $table->string('device_name', 60);
            // The minted token, encrypted, from approval until the app collects it.
            $table->text('token')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->json('abilities')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_pairings');
    }
};
