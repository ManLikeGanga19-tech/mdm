<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Devices, the enrollment tokens that admit them, and their per-device API credentials (ARCHITECTURE.md §6).
// Tokens and credentials are stored only as SHA-256 hashes.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mdm_devices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('asset_tag')->unique(); // physical label, e.g. NP-0042; can be pre-registered before enrollment
            $table->string('serial')->nullable()->unique();
            $table->string('imei')->nullable();
            $table->string('manufacturer')->nullable();
            $table->string('model')->nullable();
            $table->string('android_version')->nullable();
            $table->string('security_patch')->nullable();
            $table->unsignedInteger('agent_version_code')->nullable();
            $table->string('agent_version_name')->nullable();
            $table->foreignId('group_id')->nullable()->constrained('mdm_device_groups')->nullOnDelete();
            $table->jsonb('policy_overrides')->nullable(); // per-device exceptions to the group policy
            $table->unsignedInteger('applied_policy_version')->nullable();
            $table->text('fcm_token')->nullable();
            $table->string('state')->default('pending'); // pending (pre-registered) | enrolled | retired
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('last_checkin_at')->nullable();
            $table->timestamp('notice_acknowledged_at')->nullable();
            $table->timestamps();
        });

        Schema::create('mdm_enrollment_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->string('token_hash', 64)->unique();
            $table->foreignId('group_id')->nullable()->constrained('mdm_device_groups')->nullOnDelete();
            $table->unsignedInteger('max_uses')->default(1);
            $table->unsignedInteger('uses')->default(0);
            $table->timestamp('expires_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('mdm_device_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('mdm_devices')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('created_at');
            $table->timestamp('revoked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mdm_device_credentials');
        Schema::dropIfExists('mdm_enrollment_tokens');
        Schema::dropIfExists('mdm_devices');
    }
};
