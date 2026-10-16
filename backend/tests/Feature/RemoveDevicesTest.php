<?php

namespace Tests\Feature;

use App\Mdm\Models\Clerk;
use App\Mdm\Models\Device;
use App\Mdm\Services\HandoverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RemoveDevicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_first_and_deletes_only_with_force(): void
    {
        $test = Device::create(['asset_tag' => 'NP-TEST-02', 'state' => Device::STATE_ENROLLED]);
        Device::create(['asset_tag' => 'NP-0001', 'state' => Device::STATE_ENROLLED]);
        $clerk = Clerk::create(['staff_number' => '1001', 'name' => 'Jane']);
        app(HandoverService::class)->handOver($test, $clerk);

        $this->artisan('mdm:remove-devices', ['asset_tags' => ['NP-TEST-02']])
            ->expectsOutputToContain('1 holder records')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();
        $this->assertDatabaseHas('mdm_devices', ['asset_tag' => 'NP-TEST-02']);

        $this->artisan('mdm:remove-devices', ['asset_tags' => ['NP-TEST-02'], '--force' => true])->assertSuccessful();
        $this->assertDatabaseMissing('mdm_devices', ['asset_tag' => 'NP-TEST-02']);
        $this->assertDatabaseHas('mdm_devices', ['asset_tag' => 'NP-0001']);
        $this->assertDatabaseHas('mdm_clerks', ['staff_number' => '1001']);
        $this->assertDatabaseMissing('mdm_device_assignments', ['clerk_id' => $clerk->id]);
        $this->assertDatabaseHas('mdm_audit_log', ['action' => 'device.removed']);
    }

    public function test_an_unknown_tag_stops_everything(): void
    {
        Device::create(['asset_tag' => 'NP-TEST-02']);

        $this->artisan('mdm:remove-devices', ['asset_tags' => ['NP-TEST-02', 'NP-TYPO'], '--force' => true])
            ->expectsOutputToContain('Not found (nothing done): NP-TYPO')
            ->assertFailed();
        $this->assertDatabaseHas('mdm_devices', ['asset_tag' => 'NP-TEST-02']);
    }
}
