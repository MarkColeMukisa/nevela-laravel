<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Every refresh token a sign-in has given up (see Auth\Sessions). One that turns up again
// was copied, however many swaps ago, and the sign-in it belonged to ends. Kept only as a
// hash, and removed with its sign-in.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nevela_spent_refresh_tokens', function (Blueprint $table) {
            $table->string('token_hash', 64)->primary();
            $table->uuid('session_id')->index();
            $table->timestamp('spent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nevela_spent_refresh_tokens');
    }
};
