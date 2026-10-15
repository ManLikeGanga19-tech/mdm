<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Latest status reported by the phone at check-in (battery, network), for the phone page and the fleet list.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mdm_devices', function (Blueprint $table) {
            $table->unsignedTinyInteger('battery_percent')->nullable()->after('last_checkin_at');
            $table->boolean('battery_charging')->nullable()->after('battery_percent');
            $table->string('network_type')->nullable()->after('battery_charging'); // wifi | mobile | none
        });
    }

    public function down(): void
    {
        Schema::table('mdm_devices', function (Blueprint $table) {
            $table->dropColumn(['battery_percent', 'battery_charging', 'network_type']);
        });
    }
};
