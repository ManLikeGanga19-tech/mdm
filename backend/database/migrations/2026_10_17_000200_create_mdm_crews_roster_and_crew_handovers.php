<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Crews and the coordinator-to-coordinator handover (approved 2026-10-16): three crews rotate weekly through the
// shifts on a roster; each crew has two coordinators (admin-site accounts, users.crew_id) and its clerks
// (mdm_clerks.crew_id). At each shift change the outgoing crew hands the phones over and the incoming crew accepts
// them, phone by phone (mdm_crew_handovers + items). Dates are the shift's start date in the admin's time zone.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mdm_crews', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('crew_id')->nullable()->after('role_id')->constrained('mdm_crews')->nullOnDelete();
        });
        Schema::table('mdm_clerks', function (Blueprint $table) {
            $table->foreignId('crew_id')->nullable()->after('name')->constrained('mdm_crews')->nullOnDelete();
        });

        Schema::create('mdm_roster', function (Blueprint $table) {
            $table->id();
            $table->date('date'); // the day the shift starts (admin's time zone)
            $table->foreignId('shift_id')->constrained('mdm_shifts')->cascadeOnDelete();
            $table->foreignId('crew_id')->constrained('mdm_crews')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['date', 'shift_id']);
        });

        Schema::create('mdm_crew_handovers', function (Blueprint $table) {
            $table->id();
            // The handover that opens this shift on this date.
            $table->foreignId('shift_id')->constrained('mdm_shifts')->cascadeOnDelete();
            $table->date('date');
            $table->foreignId('from_crew_id')->nullable()->constrained('mdm_crews')->nullOnDelete();
            $table->foreignId('to_crew_id')->constrained('mdm_crews')->restrictOnDelete();
            $table->foreignId('handed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handed_at');
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->string('status')->default('pending'); // pending, accepted, disputed (some phones not received)
            $table->string('note')->nullable();
            $table->timestamps();
            $table->unique(['shift_id', 'date']);
        });

        Schema::create('mdm_crew_handover_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crew_handover_id')->constrained('mdm_crew_handovers')->cascadeOnDelete();
            $table->foreignId('device_id')->constrained('mdm_devices')->cascadeOnDelete();
            $table->string('note')->nullable(); // outgoing coordinator, e.g. "cracked screen"
            $table->boolean('received')->nullable(); // null until the incoming coordinator accepts
            $table->string('receive_note')->nullable();
            $table->timestamps();
            $table->unique(['crew_handover_id', 'device_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mdm_crew_handover_items');
        Schema::dropIfExists('mdm_crew_handovers');
        Schema::dropIfExists('mdm_roster');
        Schema::table('mdm_clerks', fn (Blueprint $table) => $table->dropConstrainedForeignId('crew_id'));
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('crew_id'));
        Schema::dropIfExists('mdm_crews');
    }
};
