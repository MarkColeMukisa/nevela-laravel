<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The activity log: who created, changed, deleted or restored which records (see
// Activity\Activity). The same thing done to many records by one person within a couple
// of minutes is one row with a count: "Ada deleted 20 products".
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('nevela_activity')) {
            return;
        }
        Schema::create('nevela_activity', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // As the roles table keeps ids: text, whatever kind of id the users table has.
            $table->string('user_id')->nullable();
            // created, updated, deleted, restored, purged
            $table->string('action', 16);
            // The resource's name: "Product".
            $table->string('resource', 64);
            $table->unsignedInteger('count')->default(1);
            // The first records' ids, as JSON: enough to point at, not a full list.
            $table->text('record_ids')->nullable();
            // What a list calls the first record, while it still exists to be read.
            $table->string('label')->nullable();
            $table->timestamps();
            $table->index('updated_at');
            $table->index(['resource', 'updated_at']);
            $table->index(['user_id', 'action', 'resource', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nevela_activity');
    }
};
