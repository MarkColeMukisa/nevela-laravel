<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The dashboard's bell: what happened that someone should know about, one row per person
// (see Notices\Notices). Several of a kind arriving before they are read are one row with
// a count ("3 new enquiries"), so a busy form can't bury the rest.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('nevela_notifications')) {
            return;
        }
        Schema::create('nevela_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // As the roles table keeps ids: text, whatever kind of id the users table has.
            $table->string('user_id');
            // What it is about: "form.received", "account.created", or the app's own name.
            $table->string('kind', 64);
            // Rows with the same group, unread, are one row whose count goes up.
            $table->string('group_key', 191)->nullable();
            $table->unsignedInteger('count')->default(1);
            $table->string('title');
            $table->string('body', 500)->nullable();
            $table->string('url', 2048)->nullable();
            // "info", or "warning" for something that needs doing.
            $table->string('tone', 16)->default('info');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'updated_at']);
            $table->index(['user_id', 'group_key', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nevela_notifications');
    }
};
