<?php

namespace Database\Seeders;

use App\Mdm\Models\DeviceGroup;
use App\Mdm\Models\Policy;
use Illuminate\Database\Seeder;

/** Starting data: the group for the shared shift phones. Accounts are created with `php artisan mdm:create-user`. */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (DeviceGroup::query()->where('name', 'Shift phones')->exists()) {
            return;
        }

        $policy = Policy::create([
            'name' => 'Shift phones',
            'payload' => ['hotspot' => 'blocked', 'blocked_apps' => []],
        ]);
        DeviceGroup::create(['name' => 'Shift phones', 'policy_id' => $policy->id]);
    }
}
