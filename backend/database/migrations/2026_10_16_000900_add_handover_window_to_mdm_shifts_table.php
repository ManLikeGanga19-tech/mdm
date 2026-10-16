<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Each shift starts with a handover window (30 min) in which the outgoing shift coordinator hands the phones to the
// incoming one. Noahport's shifts (2026-10-16): A 06:30, B 14:30, C 22:30, each running until the next one starts.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mdm_shifts', function (Blueprint $table) {
            $table->unsignedSmallInteger('handover_minutes')->default(30)->after('ends_at');
        });

        if (DB::table('mdm_shifts')->count() === 0) {
            $now = now();
            DB::table('mdm_shifts')->insert([
                ['name' => 'Shift A', 'starts_at' => '06:30', 'ends_at' => '14:30', 'handover_minutes' => 30, 'active' => true, 'created_at' => $now, 'updated_at' => $now],
                ['name' => 'Shift B', 'starts_at' => '14:30', 'ends_at' => '22:30', 'handover_minutes' => 30, 'active' => true, 'created_at' => $now, 'updated_at' => $now],
                ['name' => 'Shift C', 'starts_at' => '22:30', 'ends_at' => '06:30', 'handover_minutes' => 30, 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('mdm_shifts', function (Blueprint $table) {
            $table->dropColumn('handover_minutes');
        });
    }
};
