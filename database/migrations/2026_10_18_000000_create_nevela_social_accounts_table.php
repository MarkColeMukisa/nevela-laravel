<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Which Google or GitHub account belongs to which account here.
//
// Matched by the provider's own id for the person, not by email address: the address at
// Google can change, and a later sign-in still finds the account. The address is kept
// only to show on the Account page.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('nevela_social_accounts')) {
            return;
        }
        Schema::create('nevela_social_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Text, as the roles table keeps them: an account's id is a number or a UUID.
            $table->string('user_id', 64)->index();
            $table->string('provider', 32);
            $table->string('provider_id', 191);
            $table->string('email')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nevela_social_accounts');
    }
};
