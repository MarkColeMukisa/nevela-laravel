<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// What was turned away, and blocks made and cleared (Security\SecurityLog). One row per
// address, kind and hour, with a count: an attack of a million requests is a handful of rows,
// not a million. Kept for nevela.security.log_days, then removed in the nightly run.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('nevela_security_events')) {
            return;
        }
        Schema::create('nevela_security_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // An IPv4 address, or an IPv6 /64.
            $table->string('address', 64);
            // "scanner", "limit", "sign-in", "refused", "locked", "blocked" or "unblocked".
            $table->string('kind', 20);
            // The start of the hour the row counts.
            $table->timestamp('hour');
            $table->unsignedInteger('count')->default(1);
            // The latest request's method and path (no query: tokens travel there), and its browser.
            $table->string('method', 10)->nullable();
            $table->string('path')->nullable();
            $table->string('user_agent')->nullable();
            // For a block: its reason, and who made or cleared it.
            $table->string('detail')->nullable();
            $table->timestamp('first_at');
            $table->timestamp('last_at');
            $table->unique(['address', 'kind', 'hour']);
            $table->index('last_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nevela_security_events');
    }
};
