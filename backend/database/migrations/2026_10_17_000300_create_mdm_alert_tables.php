<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Alert rules and the alerts they raise (email phase step 3, approved 2026-10-17; ARCHITECTURE.md §6). AlertService
// checks the enabled rules every 5 minutes; each alert is emailed (Zoho SMTP) and shown on the Alerts page.
// The suggested rules are created switched off, for a super admin to set thresholds and recipients.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mdm_alert_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('metric'); // App\Mdm\AlertMetric
            $table->unsignedInteger('threshold')->nullable(); // in the metric's unit (minutes, MB, %)
            $table->string('window')->nullable(); // data metrics: day or week (admin's time zone)
            $table->foreignId('group_id')->nullable()->constrained('mdm_device_groups')->nullOnDelete(); // null = all phones
            $table->json('recipient_role_ids')->nullable(); // active accounts with these roles
            $table->json('recipient_emails')->nullable(); // extra addresses (no account needed)
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });

        Schema::create('mdm_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_id')->constrained('mdm_alert_rules')->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('mdm_devices')->cascadeOnDelete();
            // What the alert is about, so it is raised once: "device:12", "device:12@2026-10-17", "handover:3@2026-10-17:missing".
            $table->string('key');
            $table->decimal('value', 14, 2)->nullable();
            $table->string('message', 500);
            $table->timestamp('triggered_at');
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('resolved_at')->nullable(); // the condition cleared (e.g. the phone checked in again)
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['rule_id', 'key']);
            $table->index('triggered_at');
        });

        $superAdmin = DB::table('mdm_roles')->where('key', 'super_admin')->value('id');
        $now = now();
        foreach ([
            ['Phone offline', 'offline', 60, null],
            ['Hotspot data over limit', 'hotspot_data', 1024, 'day'],
            ['Mobile data over limit', 'mobile_data', 3072, 'day'],
            ['Battery low during a shift', 'battery_low', 15, null],
            ['Phone stuck on the handover lock', 'handover_lock', 30, null],
            ['Crew handover missed or disputed', 'handover_missed', 15, null],
        ] as [$name, $metric, $threshold, $window]) {
            DB::table('mdm_alert_rules')->insert([
                'name' => $name, 'metric' => $metric, 'threshold' => $threshold, 'window' => $window,
                'recipient_role_ids' => json_encode($superAdmin ? [$superAdmin] : []), 'recipient_emails' => json_encode([]),
                'enabled' => false, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mdm_alerts');
        Schema::dropIfExists('mdm_alert_rules');
    }
};
