<?php

use App\Mdm\Permission;
use App\Mdm\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Editable roles (approved 2026-10-16): roles live in mdm_roles with their permissions (App\Mdm\Permission) in
// mdm_role_permissions. The three built-in roles are created with exactly the access they had, and every account keeps
// its role (users.role -> users.role_id). Accounts gain disabled / last sign-in / invited timestamps (UTC).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mdm_roles', function (Blueprint $table) {
            $table->id();
            $table->string('key')->nullable()->unique(); // built-in roles only: super_admin, manager, coordinator
            $table->string('name')->unique();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('mdm_role_permissions', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained('mdm_roles')->cascadeOnDelete();
            $table->string('permission');
            $table->primary(['role_id', 'permission']);
        });

        $now = now();
        $ids = [];
        foreach (Role::cases() as $role) {
            $ids[$role->value] = DB::table('mdm_roles')->insertGetId([
                'key' => $role->value, 'name' => $role->name(), 'description' => $role->description(),
                'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('mdm_role_permissions')->insert(array_map(
                fn (Permission $p) => ['role_id' => $ids[$role->value], 'permission' => $p->value],
                Permission::defaultsFor($role),
            ));
        }

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('email')->constrained('mdm_roles')->restrictOnDelete();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('invited_at')->nullable();
        });
        foreach ($ids as $key => $id) {
            DB::table('users')->where('role', $key)->update(['role_id' => $id]);
        }
        DB::table('users')->whereNull('role_id')->update(['role_id' => $ids[Role::Manager->value]]);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('manager')->after('email');
        });
        // Custom roles have no equivalent: their accounts fall back to manager (read-only).
        foreach (DB::table('mdm_roles')->whereNotNull('key')->get() as $role) {
            DB::table('users')->where('role_id', $role->id)->update(['role' => $role->key]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
            $table->dropColumn(['disabled_at', 'last_login_at', 'invited_at']);
        });
        Schema::dropIfExists('mdm_role_permissions');
        Schema::dropIfExists('mdm_roles');
    }
};
