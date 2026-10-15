<?php

namespace Tests\Feature;

use App\Mdm\Models\Device;
use App\Mdm\Models\DeviceGroup;
use App\Mdm\Models\Policy;
use App\Mdm\Services\CommandService;
use App\Mdm\Services\PolicyBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PolicyBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_phone_without_a_group_gets_the_defaults(): void
    {
        $policy = app(PolicyBuilder::class)->build(Device::create(['asset_tag' => 'NP-0001']));

        $this->assertSame('allowed', $policy['hotspot']);
        $this->assertSame([], $policy['blocked_apps']);
        $this->assertTrue($policy['network_logging']);
    }

    public function test_group_rules_apply_and_a_device_override_wins(): void
    {
        $group = DeviceGroup::create(['name' => 'Shift phones', 'policy_id' => Policy::create([
            'name' => 'Shift phones', 'payload' => ['hotspot' => 'blocked', 'blocked_apps' => ['com.zhiliaoapp.musically']],
        ])->id]);
        $device = Device::create(['asset_tag' => 'NP-0001', 'group_id' => $group->id]);
        $builder = app(PolicyBuilder::class);

        $this->assertSame('blocked', $builder->build($device)['hotspot']);
        $this->assertSame(['com.zhiliaoapp.musically'], $builder->build($device)['blocked_apps']);

        app(CommandService::class)->overridePolicy($device, 'hotspot', 'allowed');
        $this->assertSame('allowed', $builder->build($device->fresh())['hotspot']);

        app(CommandService::class)->overridePolicy($device, 'hotspot', null);
        $this->assertSame('blocked', $builder->build($device->fresh())['hotspot']);
    }

    public function test_version_changes_whenever_the_effective_rules_can_change(): void
    {
        $policy = Policy::create(['name' => 'P', 'payload' => ['hotspot' => 'blocked']]);
        $device = Device::create(['asset_tag' => 'NP-0001', 'group_id' => DeviceGroup::create(['name' => 'G', 'policy_id' => $policy->id])->id]);
        $builder = app(PolicyBuilder::class);
        $v1 = $builder->build($device)['version'];

        $policy->update(['payload' => ['hotspot' => 'allowed']]);
        $this->assertSame(2, $policy->fresh()->version);
        $v2 = $builder->build($device->fresh())['version'];
        $this->assertNotSame($v1, $v2);

        $device->update(['policy_overrides' => ['hotspot' => 'blocked']]);
        $this->assertNotSame($v2, $builder->build($device->fresh())['version']);
        $this->assertDatabaseHas('mdm_audit_log', ['action' => 'policy.updated']);
    }
}
