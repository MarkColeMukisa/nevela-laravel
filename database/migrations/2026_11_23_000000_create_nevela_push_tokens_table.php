<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The phones that get push notifications (Push\Push): one row per phone, for whoever signed in
// on it last, and the sign-in it was registered with. When that sign-in ends, the row goes, so
// a phone that was signed out, or revoked from the Devices screen, hears nothing more.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('nevela_push_tokens')) {
            return;
        }
        Schema::create('nevela_push_tokens', function (Blueprint $table) {
            $table->id();
            // As the roles table keeps ids: text, whatever kind of id the users table has.
            $table->string('user_id');
            // The nevela_sessions row it was registered with: when that sign-in ends, the phone is forgotten.
            $table->uuid('session_id');
            // "ExponentPushToken[…]": Expo's address for one install of the app on one phone.
            $table->string('token')->unique();
            $table->string('platform', 16)->nullable();
            $table->timestamps();
            $table->index('user_id');
            $table->index('session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nevela_push_tokens');
    }
};
