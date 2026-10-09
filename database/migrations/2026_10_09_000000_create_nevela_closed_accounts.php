<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Deleted accounts.
//
// Closing an account, or deleting one from the Users screen, no longer removes it: it is
// marked closed, signed out everywhere, and kept so that it can be restored. And an email
// that had an account stays known after the account is removed for good, as a fingerprint
// the address can't be read back from, so the same person can't simply sign up again.
return new class extends Migration
{
    public function up(): void
    {
        $model = config('auth.providers.users.model');
        $users = is_string($model) && class_exists($model) ? (new $model)->getTable() : 'users';

        if (Schema::hasTable($users)) {
            Schema::table($users, function (Blueprint $table) use ($users) {
                if (! Schema::hasColumn($users, 'closed_at')) {
                    // Set: the account is closed. It can't sign in, and isn't among the users.
                    $table->timestamp('closed_at')->nullable()->index();
                }
                if (! Schema::hasColumn($users, 'closed_by')) {
                    // The id of whoever closed it: the account's own id when its owner did.
                    $table->string('closed_by')->nullable();
                }
            });
        }

        Schema::create('nevela_blocked_emails', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // HMAC-SHA256 of the address, keyed with the app key. Not the address.
            $table->char('fingerprint', 64)->unique();
            // Enough to recognise it by, for whoever decides to allow it again: "m•••@gmail.com".
            $table->string('hint');
            // The closed account it belongs to, while that is still kept. Null once the
            // account has been removed for good: then this row is all that is left of it.
            $table->string('user_id')->nullable()->index();
            $table->timestamp('blocked_at');
        });
    }

    public function down(): void
    {
        // The two columns stay: an app may have had its own before this ran, and a closed
        // account that lost its mark would be an open one again.
        Schema::dropIfExists('nevela_blocked_emails');
    }
};
