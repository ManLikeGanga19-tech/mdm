<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Remote actions (lock, reboot, reset_password, wipe). Lifecycle: queued -> delivered -> succeeded | failed | expired.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mdm_commands', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('device_id')->constrained('mdm_devices')->cascadeOnDelete();
            $table->string('type');
            $table->jsonb('payload')->nullable();
            $table->string('status')->default('queued');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->jsonb('result')->nullable();
            $table->index(['device_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mdm_commands');
    }
};
