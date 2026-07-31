<?php
/**
 * Create roles, permissions, overrides, outlets, login histories, activity logs schema.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Roles table
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('role_name')->unique();
            $table->text('description')->nullable();
            $table->string('status')->default('active'); // active, inactive
            $table->timestamps();
        });

        // 2. Permissions table
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('module_name');
            $table->string('permission_name')->unique();
            $table->timestamps();
        });

        // 3. Role Permissions table
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->onDelete('cascade');
            $table->foreignId('permission_id')->constrained('permissions')->onDelete('cascade');
        });

        // 4. User Override Permissions table
        Schema::create('user_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('permission_id')->constrained('permissions')->onDelete('cascade');
            $table->boolean('is_allowed')->default(true);
        });

        // 5. User Outlets table
        Schema::create('user_outlets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('outlet_id')->constrained('locations')->onDelete('cascade');
        });

        // 6. Login History table
        Schema::create('login_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->timestamp('login_time')->useCurrent();
            $table->timestamp('logout_time')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('device')->nullable();
            $table->string('status')->default('active'); // active, logged_out, expired
            $table->string('session_token', 80)->unique();
            $table->timestamps();
        });

        // 7. Activity Logs table
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('action');
            $table->string('module');
            $table->timestamp('created_at')->useCurrent();
        });

        // 8. Update users table structure
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable()->unique()->after('name');
            $table->string('phone')->nullable()->after('email');
            $table->foreignId('role_id')->nullable()->after('password')->constrained('roles')->onDelete('set null');
            $table->string('status')->default('active')->after('role_id'); // active, inactive, suspended
            $table->string('profile_image')->nullable()->after('status');
            $table->string('employee_id')->nullable()->unique()->after('profile_image');
            $table->string('department')->nullable()->after('employee_id');
            $table->string('position')->nullable()->after('department');
            $table->date('joining_date')->nullable()->after('position');
            $table->foreignId('default_outlet_id')->nullable()->after('joining_date')->constrained('locations')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['default_outlet_id']);
            $table->dropForeign(['role_id']);
            $table->dropColumn([
                'username', 'phone', 'role_id', 'status', 'profile_image', 
                'employee_id', 'department', 'position', 'joining_date', 'default_outlet_id'
            ]);
        });

        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('login_histories');
        Schema::dropIfExists('user_outlets');
        Schema::dropIfExists('user_permissions');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
