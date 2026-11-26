<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Addresses turned away (Security\Addresses): blocked for a while for too many strikes (a
// scanner's probes, too many requests), or by an administrator. One row per block.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('nevela_blocked_addresses')) {
            return;
        }
        Schema::create('nevela_blocked_addresses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // An IPv4 address, or an IPv6 /64 ("2001:db8:1:2::/64").
            $table->string('address', 64);
            // "scanner", "limit" or "manual".
            $table->string('reason', 20);
            $table->string('note')->nullable();
            // Who blocked it, by user id, for one an administrator added.
            $table->string('blocked_by')->nullable();
            $table->unsignedInteger('strikes')->default(0);
            $table->timestamp('blocked_at');
            // Null: until it is cleared.
            $table->timestamp('expires_at')->nullable();
            $table->index('address');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nevela_blocked_addresses');
    }
};
