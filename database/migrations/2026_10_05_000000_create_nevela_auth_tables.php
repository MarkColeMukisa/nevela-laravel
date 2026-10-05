<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// What two-factor sign-in, passkeys, the profile picture and the list of devices need.
// Columns are only added where they are missing, so an app that already has an "avatar"
// column, say, keeps its own.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'avatar')) {
            Schema::table('users', function (Blueprint $table) {
                // The key of an uploaded image (see nevela_uploads), not a URL.
                $table->string('avatar', 512)->nullable();
            });
        }

        // A device is a Sanctum token; these say which browser and where it signed in from.
        if (Schema::hasTable('personal_access_tokens')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                if (! Schema::hasColumn('personal_access_tokens', 'ip_address')) {
                    $table->string('ip_address', 45)->nullable();
                }
                if (! Schema::hasColumn('personal_access_tokens', 'user_agent')) {
                    $table->string('user_agent', 512)->nullable();
                }
            });
        }

        Schema::create('nevela_two_factor', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('user_id')->unique();
            // The authenticator app's secret, encrypted. Null when only email codes are used.
            $table->text('secret')->nullable();
            $table->timestamp('totp_confirmed_at')->nullable();
            // The 30-second step of the last code accepted, so the same code isn't taken twice.
            $table->unsignedBigInteger('totp_last_step')->nullable();
            // Hashes of the backup codes not used yet, encrypted.
            $table->text('backup_codes')->nullable();
            $table->timestamp('enabled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('nevela_passkeys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('user_id')->index();
            $table->string('name')->nullable();
            $table->string('credential_id', 1024)->unique();
            $table->text('public_key');
            $table->unsignedBigInteger('counter')->default(0);
            $table->json('transports')->nullable();
            $table->string('aaguid')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nevela_passkeys');
        Schema::dropIfExists('nevela_two_factor');
    }
};
