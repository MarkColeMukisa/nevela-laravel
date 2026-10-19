<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One row per signed-in device (see Auth\Sessions). A sign-in holds a short access token
// (a Sanctum token named after its row) and a refresh token, kept here only as a hash and
// swapped for a new one each time it is used.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nevela_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('user_id')->index();
            // SHA-256 of the refresh token now in use. The token itself is never stored.
            $table->string('token_hash', 64)->unique();
            // The one it replaced. Presented again later, it was copied: the sign-in ends.
            $table->string('previous_token_hash', 64)->nullable()->index();
            $table->timestamp('rotated_at')->nullable();
            $table->string('name')->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();
            // The last refresh: a sign-in unused for longer than the idle limit has ended.
            $table->timestamp('last_seen_at')->nullable()->index();
            // When it ends however much it is used. Null for no limit.
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('revoked_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nevela_sessions');
    }
};
