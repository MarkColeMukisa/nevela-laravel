<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nevela\Laravel\Models\Role;

// Roles and permissions, and switching an account off.
//
// Until now every signed-in user could do everything. So that upgrading an app changes
// nothing about who can do what, every user it already has is made an ADMIN here. Give
// them narrower roles afterwards, from the dashboard's Users screen.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nevela_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 80)->unique();
            $table->string('description', 500)->nullable();
            // A JSON list of grants: permissions, and patterns such as "products.*".
            $table->text('grants');
            // One the app depends on: it can be edited, and not renamed or deleted.
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::create('nevela_role_user', function (Blueprint $table) {
            $table->foreignUuid('role_id')->constrained('nevela_roles')->cascadeOnDelete();
            // A string, so it holds a numeric id or a UUID, whichever the app's users have.
            $table->string('user_id');
            $table->primary(['role_id', 'user_id']);
            $table->index('user_id');
        });

        $model = config('auth.providers.users.model');
        $user = is_string($model) && class_exists($model) ? new $model : null;
        $users = $user?->getTable() ?? 'users';
        $key = $user?->getKeyName() ?? 'id';

        if (Schema::hasTable($users) && ! Schema::hasColumn($users, 'active')) {
            Schema::table($users, function (Blueprint $table) {
                // False: the account can't sign in, and is signed out everywhere.
                $table->boolean('active')->default(true);
            });
        }

        $now = now();
        $admin = null;
        foreach (Role::defaults() as $role) {
            $id = (string) Str::uuid();
            DB::table('nevela_roles')->insert([
                'id' => $id,
                'name' => $role['name'],
                'description' => $role['description'],
                'grants' => json_encode($role['grants']),
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $admin ??= $role['name'] === Role::ADMIN ? $id : null;
        }

        if ($admin !== null && Schema::hasTable($users)) {
            DB::table($users)->orderBy($key)->select($key)->chunk(500, function ($rows) use ($admin, $key) {
                DB::table('nevela_role_user')->insert($rows->map(fn ($row) => ['role_id' => $admin, 'user_id' => (string) $row->{$key}])->all());
            });
        }
    }

    public function down(): void
    {
        // The "active" column stays: an app may have had one of its own before this ran.
        Schema::dropIfExists('nevela_role_user');
        Schema::dropIfExists('nevela_roles');
    }
};
