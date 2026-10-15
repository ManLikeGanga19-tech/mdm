<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Shared shift phones: coordinators record at each handover which clerk holds which phone, so usage and network
// activity can be attributed to the clerk for the time they held it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mdm_clerks', function (Blueprint $table) {
            $table->id();
            $table->string('staff_number')->unique();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('mdm_device_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('mdm_devices')->cascadeOnDelete();
            $table->foreignId('clerk_id')->constrained('mdm_clerks')->restrictOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable(); // null = holding the phone now
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();
            $table->index(['device_id', 'started_at']);
        });

        // At most one current holder per phone, and one current phone per clerk.
        DB::statement('CREATE UNIQUE INDEX mdm_device_assignments_one_open_per_device ON mdm_device_assignments (device_id) WHERE ended_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX mdm_device_assignments_one_open_per_clerk ON mdm_device_assignments (clerk_id) WHERE ended_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('mdm_device_assignments');
        Schema::dropIfExists('mdm_clerks');
    }
};
