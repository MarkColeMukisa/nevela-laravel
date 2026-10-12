<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Public forms: what was refused, and the mail a form causes.
//
// A form anyone can send is sent by bots too. What is turned away is counted, so that
// protection which works can be told from a form nobody uses. And because every send can
// cause mail, to the address that was typed and to the owner, each of those is counted
// too: that is what keeps the form from being a way to mail strangers, and the owner's
// inbox from being buried.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nevela_form_blocks')) {
            Schema::create('nevela_form_blocks', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('resource');
                // honeypot, forged, too_fast, expired, robot, disposable, undeliverable, throttled, unconfigured, unreachable
                $table->string('reason', 32);
                $table->date('day');
                $table->unsignedInteger('count')->default(0);
                $table->unique(['resource', 'reason', 'day']);
            });
        }

        if (! Schema::hasTable('nevela_public_mail')) {
            Schema::create('nevela_public_mail', function (Blueprint $table) {
                $table->uuid('id')->primary();
                // "visitor": to an address a form was given. "owner": a notice that a form was sent.
                $table->string('kind', 16);
                // A visitor's address is kept as a fingerprint: enough to count by, not to read back.
                $table->string('recipient', 64);
                $table->timestamp('sent_at')->index();
                $table->index(['kind', 'recipient', 'sent_at']);
            });
        }

        if (! Schema::hasTable('nevela_owner_digest')) {
            // Notices to the owner past the hour's limit, held for one email that names them all.
            Schema::create('nevela_owner_digest', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('subject');
                $table->string('line', 500)->nullable();
                $table->string('url', 2048)->nullable();
                $table->timestamp('held_at')->index();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('nevela_owner_digest');
        Schema::dropIfExists('nevela_public_mail');
        Schema::dropIfExists('nevela_form_blocks');
    }
};
