<?php

namespace Tests\Feature;

use App\Mdm\Models\Clerk;
use App\Mdm\Models\Device;
use App\Mdm\Models\DeviceAssignment;
use App\Mdm\Services\HandoverService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class HandoverServiceTest extends TestCase
{
    use RefreshDatabase;

    private HandoverService $handover;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handover = app(HandoverService::class);
    }

    public function test_handover_ends_the_previous_holder_and_starts_the_new_one(): void
    {
        $phone = Device::create(['asset_tag' => 'NP-0001']);
        $day = Clerk::create(['staff_number' => '1001', 'name' => 'Day Clerk']);
        $night = Clerk::create(['staff_number' => '1002', 'name' => 'Night Clerk']);

        $this->handover->handOver($phone, $day, at: Carbon::parse('2026-10-16 03:00:00', 'UTC'));
        $this->handover->handOver($phone, $night, at: Carbon::parse('2026-10-16 15:00:00', 'UTC'));

        $this->assertTrue($phone->fresh()->currentAssignment->clerk->is($night));
        $this->assertEquals('2026-10-16 15:00:00', $day->assignments()->sole()->ended_at->utc()->toDateTimeString());
        $this->assertTrue($this->handover->holderAt($phone, Carbon::parse('2026-10-16 10:00:00', 'UTC'))->is($day));
        $this->assertTrue($this->handover->holderAt($phone, Carbon::parse('2026-10-16 20:00:00', 'UTC'))->is($night));
        $this->assertNull($this->handover->holderAt($phone, Carbon::parse('2026-10-16 01:00:00', 'UTC')));
        $this->assertDatabaseCount('mdm_audit_log', 2);
    }

    public function test_a_clerk_taking_another_phone_gives_up_the_first(): void
    {
        $a = Device::create(['asset_tag' => 'NP-0001']);
        $b = Device::create(['asset_tag' => 'NP-0002']);
        $clerk = Clerk::create(['staff_number' => '1001', 'name' => 'Clerk']);

        $this->handover->handOver($a, $clerk);
        $this->handover->handOver($b, $clerk);

        $this->assertNull($a->fresh()->currentAssignment);
        $this->assertTrue($b->fresh()->currentAssignment->clerk->is($clerk));
    }

    public function test_handing_over_to_the_current_holder_changes_nothing(): void
    {
        $phone = Device::create(['asset_tag' => 'NP-0001']);
        $clerk = Clerk::create(['staff_number' => '1001', 'name' => 'Clerk']);

        $first = $this->handover->handOver($phone, $clerk);
        $second = $this->handover->handOver($phone, $clerk);

        $this->assertTrue($first->is($second));
        $this->assertDatabaseCount('mdm_device_assignments', 1);
    }

    public function test_return_leaves_the_phone_with_nobody(): void
    {
        $phone = Device::create(['asset_tag' => 'NP-0001']);
        $this->handover->handOver($phone, Clerk::create(['staff_number' => '1001', 'name' => 'Clerk']));

        $this->handover->returnPhone($phone);

        $this->assertNull($phone->fresh()->currentAssignment);
        $this->assertDatabaseHas('mdm_audit_log', ['action' => 'phone_returned']);
    }

    public function test_database_refuses_two_current_holders_for_one_phone(): void
    {
        $phone = Device::create(['asset_tag' => 'NP-0001']);
        $a = Clerk::create(['staff_number' => '1001', 'name' => 'A']);
        $b = Clerk::create(['staff_number' => '1002', 'name' => 'B']);
        DeviceAssignment::create(['device_id' => $phone->id, 'clerk_id' => $a->id, 'started_at' => now()]);

        $this->expectException(QueryException::class);
        DeviceAssignment::create(['device_id' => $phone->id, 'clerk_id' => $b->id, 'started_at' => now()]);
    }
}
