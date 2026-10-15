<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Milestone B telemetry (ARCHITECTURE.md §6). Raw uploads are kept in mdm_ingest_batches (deduplicated on the
// phone-generated batch_uuid) and processed into the tables below by a queued job. High-volume tables are pruned
// after the retention period (default 90 days).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mdm_ingest_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('mdm_devices')->cascadeOnDelete();
            $table->uuid('batch_uuid')->unique();
            $table->string('kind'); // usage | network_logs | inventory
            $table->jsonb('raw');
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->index('received_at');
        });

        // Bytes per app per network per hour. Shared/unknown UIDs use sentinels: __tethering__, __removed__, __uid_<n>__.
        Schema::create('mdm_usage_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('mdm_devices')->cascadeOnDelete();
            $table->string('package_name');
            $table->string('network_type'); // mobile | wifi
            $table->unsignedBigInteger('rx_bytes');
            $table->unsignedBigInteger('tx_bytes');
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->unique(['device_id', 'package_name', 'network_type', 'period_start']);
            $table->index(['device_id', 'period_start']);
        });

        // Android network logging: DNS lookups and TCP connects, with the app that made them.
        Schema::create('mdm_network_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('mdm_devices')->cascadeOnDelete();
            $table->string('log_type'); // dns | connect
            $table->string('package_name')->nullable();
            $table->string('host')->nullable();
            $table->string('ip', 64)->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->timestamp('occurred_at');
            $table->index(['device_id', 'occurred_at']);
        });

        // One row per check-in: battery and network over time.
        Schema::create('mdm_status_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('mdm_devices')->cascadeOnDelete();
            $table->unsignedTinyInteger('battery_percent')->nullable();
            $table->boolean('battery_charging')->nullable();
            $table->string('network_type')->nullable();
            $table->timestamp('reported_at');
            $table->index(['device_id', 'reported_at']);
        });

        Schema::create('mdm_app_inventory', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('mdm_devices')->cascadeOnDelete();
            $table->string('package_name');
            $table->string('label')->nullable();
            $table->string('version_name')->nullable();
            $table->unsignedBigInteger('version_code')->nullable();
            $table->boolean('system')->default(false);
            $table->timestamp('last_seen_at');
            $table->unique(['device_id', 'package_name']);
        });

        // What counts as work: a web address (matches the domain and its subdomains) or an app package.
        Schema::create('mdm_work_rules', function (Blueprint $table) {
            $table->id();
            $table->string('kind'); // domain | app
            $table->string('pattern');
            $table->string('label');
            $table->timestamps();
            $table->unique(['kind', 'pattern']);
        });

        // Shift definitions for reports; a shift may cross midnight (ends_at < starts_at).
        Schema::create('mdm_shifts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // Small settings, e.g. peak_hours, retention_days.
        Schema::create('mdm_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->jsonb('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['mdm_settings', 'mdm_shifts', 'mdm_work_rules', 'mdm_app_inventory', 'mdm_status_reports',
            'mdm_network_logs', 'mdm_usage_samples', 'mdm_ingest_batches'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
