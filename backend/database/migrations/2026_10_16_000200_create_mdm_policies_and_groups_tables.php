<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Policies are our own versioned JSON (ARCHITECTURE.md §5); each device group points at one.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mdm_policies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->jsonb('payload');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('mdm_device_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->foreignId('policy_id')->constrained('mdm_policies')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mdm_device_groups');
        Schema::dropIfExists('mdm_policies');
    }
};
