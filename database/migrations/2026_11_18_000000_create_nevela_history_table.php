<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A record's history: each action done to it, by whom, and what it changed (see
// History\History). "Accepted by Mercy Auma at 10:42". Unlike the activity log, nothing is
// merged: every step is a row, and the person a record belongs to may read its own.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('nevela_history')) {
            return;
        }
        Schema::create('nevela_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // The resource's name, "Order", and the record's id, as text whatever kind it is.
            $table->string('resource', 64);
            $table->string('record_id');
            // The action's name and what it was called when it was done: "accept", "Accept".
            $table->string('action', 64);
            $table->string('label');
            $table->string('user_id')->nullable();
            // What it changed, as JSON: {"status": {"from": "pending", "to": "accepted"}}.
            $table->text('changes')->nullable();
            // What the person filled in for it, as JSON: {"reason": "Out of mangoes"}.
            $table->text('input')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['resource', 'record_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nevela_history');
    }
};
