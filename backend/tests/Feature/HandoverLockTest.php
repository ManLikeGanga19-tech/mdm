<?php

namespace Tests\Feature;

use App\Mdm\Models\Clerk;
use App\Mdm\Models\Device;
use App\Mdm\Models\DeviceCredential;
use App\Mdm\Models\DeviceGroup;
use App\Mdm\Models\Policy;
use App\Mdm\Services\EnrollmentService;
use App\Mdm\Services\HandoverEnforcement;
use App\Mdm\Services\HandoverService;
use App\Mdm\Services\PolicyBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Phones not handed over in a closed handover window lock until a handover is recorded (Shift A window 06:30–07:00 EAT). */
class HandoverLockTest extends TestCase
{
    use RefreshDatabase;

    private Device $phone;

    private Policy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = Policy::create(['name' => 'Shift phones', 'payload' => ['hotspot' => 'blocked', 'handover_lock' => true]]);
        $group = DeviceGroup::create(['name' => 'Shift phones', 'policy_id' => $this->policy->id]);
        $this->phone = Device::create(['asset_tag' => 'NP-0001', 'state' => Device::STATE_ENROLLED, 'group_id' => $group->id]);
    }

    private function eat(string $time): Carbon
    {
        return Carbon::parse($time, 'Africa/Nairobi')->utc();
    }

    private function locked(): bool
    {
        return app(HandoverEnforcement::class)->isLocked($this->phone->fresh());
    }

    private function handOver(string $at): void
    {
        app(HandoverService::class)->handOver($this->phone->fresh(), Clerk::firstOrCreate(['staff_number' => '1001'], ['name' => 'Jane']), at: $this->eat($at));
    }

    public function test_only_groups_with_the_switch_on_are_locked(): void
    {
        $this->travelTo($this->eat('2026-10-16 07:10'));
        $this->assertTrue($this->locked());

        $this->policy->update(['payload' => ['hotspot' => 'blocked', 'handover_lock' => false]]);
        $this->assertFalse($this->locked());
    }

    public function test_a_handover_in_the_window_early_or_late_unlocks(): void
    {
        $this->travelTo($this->eat('2026-10-16 06:50'));
        $this->handOver('2026-10-16 06:45');
        $this->travelTo($this->eat('2026-10-16 07:05'));
        $this->assertFalse($this->locked(), 'on time');

        $this->travelTo($this->eat('2026-10-16 15:05')); // Shift B window closed, no handover yet
        $this->assertTrue($this->locked(), 'missing');

        $this->travelTo($this->eat('2026-10-16 15:20'));
        $this->handOver('2026-10-16 15:20'); // late
        $this->assertFalse($this->locked(), 'late handover unlocks');

        $this->travelTo($this->eat('2026-10-16 22:20'));
        $this->handOver('2026-10-16 22:10'); // early for Shift C
        $this->travelTo($this->eat('2026-10-16 23:05'));
        $this->assertFalse($this->locked(), 'early');
    }

    public function test_release_lasts_until_the_next_window_closes(): void
    {
        $this->travelTo($this->eat('2026-10-16 07:10'));
        $this->assertTrue($this->locked());

        app(HandoverEnforcement::class)->release($this->phone->fresh());
        $this->assertFalse($this->locked());
        $this->assertDatabaseHas('mdm_audit_log', ['action' => 'handover_lock.released']);

        $this->travelTo($this->eat('2026-10-16 15:01'));
        $this->assertTrue($this->locked());
    }

    public function test_checkin_tells_the_phone_and_the_rules_version_changes(): void
    {
        $token = Str::random(48);
        DeviceCredential::create(['device_id' => $this->phone->id, 'token_hash' => EnrollmentService::hash($token), 'created_at' => now()]);

        $this->travelTo($this->eat('2026-10-16 07:10'));
        $locked = $this->withToken($token)->postJson('/api/device/v1/checkin')->assertOk();
        $this->assertTrue($locked->json('policy.handover_lock'));

        $this->handOver('2026-10-16 07:15');
        $unlocked = $this->withToken($token)->postJson('/api/device/v1/checkin')->assertOk();
        $this->assertFalse($unlocked->json('policy.handover_lock'));
        $this->assertNotSame($locked->json('policy.version'), $unlocked->json('policy.version'));
        $this->assertSame($unlocked->json('policy.version'), app(PolicyBuilder::class)->build($this->phone->fresh())['version']);
    }

    public function test_the_wake_job_runs_only_right_after_a_window_closes(): void
    {
        $enforcement = app(HandoverEnforcement::class);

        $this->assertTrue($enforcement->windowJustClosed($this->eat('2026-10-16 07:00:30')));
        $this->assertFalse($enforcement->windowJustClosed($this->eat('2026-10-16 07:05')));
        $this->assertTrue($enforcement->windowJustClosed($this->eat('2026-10-16 23:00:10')));
    }
}
