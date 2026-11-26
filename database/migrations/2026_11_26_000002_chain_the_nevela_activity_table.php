<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The activity log made tamper-evident (Activity\Chain). From here each entry is written once
// and never changed: it carries its place in the chain (seq), the hash of the entry before,
// its own hash under a key the database doesn't hold, and which key that was. Entries made
// before this are kept as they were, unsealed.
//
// - nevela_activity_head: one row, the newest entry's seq and hash, sealed. Writers lock it, so
//   two never take the same place; and deleting the newest entries shows, as the head no
//   longer matches.
// - nevela_activity_anchors: where the chain began (genesis), and where it starts again after
//   the oldest entries are removed (prune), each sealed.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('nevela_activity') && ! Schema::hasColumn('nevela_activity', 'seq')) {
            Schema::table('nevela_activity', function (Blueprint $table) {
                $table->unsignedBigInteger('seq')->nullable()->unique();
                // The line the page shows: the same person, change and resource within a couple of minutes.
                $table->uuid('group_id')->nullable()->index();
                $table->string('prev_hash', 64)->nullable();
                $table->string('hash', 64)->nullable();
                $table->string('key_id', 16)->nullable();
                // The page reads one resource's newest entries by this.
                $table->index(['resource', 'seq']);
            });
            // Each entry from before is a line of its own, as it was shown.
            DB::table('nevela_activity')->whereNull('group_id')->update(['group_id' => DB::raw('id')]);
        }
        if (! Schema::hasTable('nevela_activity_head')) {
            Schema::create('nevela_activity_head', function (Blueprint $table) {
                $table->unsignedTinyInteger('id')->primary();
                $table->unsignedBigInteger('seq')->default(0);
                $table->string('hash', 64)->default('');
                $table->string('key_id', 16)->nullable();
                $table->string('seal', 64)->nullable();
            });
            DB::table('nevela_activity_head')->insert(['id' => 1, 'seq' => 0, 'hash' => '']);
        }
        if (! Schema::hasTable('nevela_activity_anchors')) {
            Schema::create('nevela_activity_anchors', function (Blueprint $table) {
                $table->uuid('id')->primary();
                // "genesis" or "prune".
                $table->string('kind', 10);
                // The seq the chain (re)starts at, and the hash the entry there chains from.
                $table->unsignedBigInteger('seq');
                $table->string('prev_hash', 64)->default('');
                // For a prune: how many entries went.
                $table->unsignedBigInteger('removed')->default(0);
                // When, as written and sealed ("2026-11-26 12:00:00").
                $table->string('at', 19);
                $table->string('key_id', 16);
                $table->string('seal', 64);
                $table->index(['kind', 'seq']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('nevela_activity_anchors');
        Schema::dropIfExists('nevela_activity_head');
        if (Schema::hasTable('nevela_activity') && Schema::hasColumn('nevela_activity', 'seq')) {
            Schema::table('nevela_activity', function (Blueprint $table) {
                $table->dropIndex(['resource', 'seq']);
                $table->dropUnique(['seq']);
                $table->dropIndex(['group_id']);
                $table->dropColumn(['seq', 'group_id', 'prev_hash', 'hash', 'key_id']);
            });
        }
    }
};
