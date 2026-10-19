<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add can_use_ai to users table
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_use_ai')->default(true)->after('status');
        });

        // 2. Migrate can_use_ai values from user_permissions to users
        DB::statement("
            UPDATE users u
            SET can_use_ai = COALESCE((
                SELECT up.can_use_ai FROM user_permissions up WHERE up.user_id = u.id
            ), true)
        ");

        // 3. Change role enum: add 'owner', remove 'moderator'
        // Drop existing enum constraint first
        DB::statement("ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check");

        // Convert existing 'moderator' → 'admin'
        DB::statement("UPDATE users SET role = 'admin' WHERE role = 'moderator'");

        // Set the earliest admin per tenant as 'owner'
        DB::statement("
            UPDATE users
            SET role = 'owner'
            WHERE id IN (
                SELECT DISTINCT ON (tenant_id) id
                FROM users
                WHERE tenant_id IS NOT NULL AND role = 'admin'
                ORDER BY tenant_id, id ASC
            )
        ");

        // Recreate enum constraint with new values
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('owner', 'admin', 'agent'))");

        // 4. Set default allowed_pages for all tenants
        $allPages = json_encode(['dashboard', 'messages', 'comments', 'email_archive', 'settings', 'crm', 'ads']);

        $tenants = DB::table('tenants')->get();
        foreach ($tenants as $tenant) {
            $settings = json_decode($tenant->settings ?? '{}', true) ?: [];
            $settings['allowed_pages'] = json_decode($allPages, true);
            DB::table('tenants')->where('id', $tenant->id)->update([
                'settings' => json_encode($settings),
            ]);
        }

        // 5. Drop user_permissions table
        Schema::dropIfExists('user_permissions');
    }

    public function down(): void
    {
        // Recreate user_permissions table
        Schema::create('user_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->jsonb('dashboard')->nullable();
            $table->jsonb('messages')->nullable();
            $table->jsonb('comments')->nullable();
            $table->jsonb('users')->nullable();
            $table->jsonb('settings')->nullable();
            $table->boolean('can_use_ai')->default(false);
            $table->timestamps();
        });

        // Revert role constraint
        DB::statement("ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check");
        DB::statement("UPDATE users SET role = 'admin' WHERE role = 'owner'");

        // Drop can_use_ai from users
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('can_use_ai');
        });
    }
};
