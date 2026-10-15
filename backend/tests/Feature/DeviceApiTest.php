<?php

namespace Tests\Feature;

use App\Mdm\Models\Command;
use App\Mdm\Models\Device;
use App\Mdm\Models\DeviceGroup;
use App\Mdm\Models\Policy;
use App\Mdm\Services\CommandService;
use App\Mdm\Services\EnrollmentService;
use App\Mdm\Services\PolicyBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Enrollment, device auth, check-in and the command lifecycle, as the agent uses them. */
class DeviceApiTest extends TestCase
{
    use RefreshDatabase;

    private DeviceGroup $group;

    protected function setUp(): void
    {
        parent::setUp();
        $policy = Policy::create(['name' => 'Shift phones', 'payload' => ['hotspot' => 'blocked', 'blocked_apps' => ['com.zhiliaoapp.musically']]]);
        $this->group = DeviceGroup::create(['name' => 'Shift phones', 'policy_id' => $policy->id]);
    }

    private function code(int $uses = 6): string
    {
        return app(EnrollmentService::class)->issueCode('Pilot', $this->group, $uses, 7)[0];
    }

    private function enroll(string $code, string $tag = 'NP-0001', string $serial = 'SER1'): TestResponse
    {
        return $this->postJson('/api/device/v1/enroll', [
            'enrollment_token' => $code, 'asset_tag' => $tag, 'serial' => $serial,
            'manufacturer' => 'Ulefone', 'model' => 'RugKing', 'android_version' => '14',
            'security_patch' => '2026-09-05', 'agent_version_code' => 4, 'agent_version_name' => '0.3.0',
        ]);
    }

    public function test_enrollment_registers_the_phone_into_the_code_group(): void
    {
        $response = $this->enroll($this->code())->assertCreated()->assertJsonStructure(['device_uuid', 'device_token']);

        $device = Device::sole();
        $this->assertSame('NP-0001', $device->asset_tag);
        $this->assertSame(Device::STATE_ENROLLED, $device->state);
        $this->assertTrue($device->group->is($this->group));
        $this->assertSame($device->uuid, $response->json('device_uuid'));
        $this->assertDatabaseMissing('mdm_device_credentials', ['token_hash' => $response->json('device_token')]); // hashed
    }

    public function test_pre_registered_phone_is_matched_by_asset_tag(): void
    {
        $pending = Device::create(['asset_tag' => 'NP-0001']);

        $this->enroll($this->code())->assertCreated();

        $this->assertSame(1, Device::count());
        $this->assertSame(Device::STATE_ENROLLED, $pending->fresh()->state);
    }

    public function test_wrong_used_up_or_expired_codes_are_refused(): void
    {
        $this->enroll('NPE-WRONG')->assertUnauthorized();

        $once = $this->code(uses: 1);
        $this->enroll($once)->assertCreated();
        $this->enroll($once, 'NP-0002', 'SER2')->assertUnauthorized();

        $this->postJson('/api/device/v1/enrollment-code/check', ['enrollment_token' => $once])->assertUnauthorized();
        $this->postJson('/api/device/v1/enrollment-code/check', ['enrollment_token' => strtolower($this->code())])
            ->assertOk()->assertJson(['group' => 'Shift phones', 'uses_left' => 6]);
    }

    public function test_re_enrolling_revokes_the_old_credential(): void
    {
        $code = $this->code();
        $old = $this->enroll($code)->json('device_token');
        $new = $this->enroll($code)->json('device_token');

        $this->withToken($old)->postJson('/api/device/v1/checkin')->assertUnauthorized();
        $this->withToken($new)->postJson('/api/device/v1/checkin')->assertOk();
        $this->assertSame(1, Device::count());
    }

    public function test_checkin_stores_status_and_returns_policy(): void
    {
        $token = $this->enroll($this->code())->json('device_token');

        $response = $this->withToken($token)->postJson('/api/device/v1/checkin', [
            'battery_percent' => 64, 'battery_charging' => false, 'network_type' => 'mobile',
            'notice_acknowledged_at' => '2026-10-16T06:00:00Z',
        ])->assertOk();

        $this->assertSame('blocked', $response->json('policy.hotspot'));
        $this->assertSame(['com.zhiliaoapp.musically'], $response->json('policy.blocked_apps'));
        $device = Device::sole();
        $this->assertSame(64, $device->battery_percent);
        $this->assertSame('mobile', $device->network_type);
        $this->assertNotNull($device->last_checkin_at);
        $this->assertNotNull($device->notice_acknowledged_at);

        $this->withToken($token)->postJson('/api/device/v1/checkin', ['applied_policy_version' => $response->json('policy.version')]);
        $this->assertSame(app(PolicyBuilder::class)->build($device->fresh())['version'], $device->fresh()->applied_policy_version);
    }

    public function test_command_lifecycle_queued_delivered_succeeded(): void
    {
        $token = $this->enroll($this->code())->json('device_token');
        $command = app(CommandService::class)->send(Device::sole(), 'lock');

        $this->withToken($token)->postJson('/api/device/v1/checkin')
            ->assertJsonPath('commands.0.uuid', $command->uuid)
            ->assertJsonPath('commands.0.type', 'lock');
        $this->assertSame('delivered', $command->fresh()->status);

        $this->withToken($token)->postJson("/api/device/v1/commands/{$command->uuid}/result", ['status' => 'succeeded'])->assertOk();
        $this->assertSame('succeeded', $command->fresh()->status);
        $this->assertNotNull($command->fresh()->completed_at);

        // A repeated result changes nothing; the command is no longer handed out.
        $this->withToken($token)->postJson("/api/device/v1/commands/{$command->uuid}/result", ['status' => 'failed'])->assertOk();
        $this->assertSame('succeeded', $command->fresh()->status);
        $this->withToken($token)->postJson('/api/device/v1/checkin')->assertJsonCount(0, 'commands');
    }

    public function test_a_phone_cannot_touch_another_phones_commands(): void
    {
        $code = $this->code();
        $tokenA = $this->enroll($code, 'NP-0001', 'A')->json('device_token');
        $this->enroll($code, 'NP-0002', 'B');
        $other = app(CommandService::class)->send(Device::where('asset_tag', 'NP-0002')->sole(), 'wipe');

        $this->withToken($tokenA)->postJson("/api/device/v1/commands/{$other->uuid}/result", ['status' => 'succeeded'])->assertNotFound();
        $this->assertSame('queued', $other->fresh()->status);
    }

    public function test_unauthenticated_and_retired_phones_are_refused(): void
    {
        $this->postJson('/api/device/v1/checkin')->assertUnauthorized();
        $this->withToken('nonsense')->postJson('/api/device/v1/checkin')->assertUnauthorized();

        $token = $this->enroll($this->code())->json('device_token');
        Device::sole()->update(['state' => Device::STATE_RETIRED]);
        $this->withToken($token)->postJson('/api/device/v1/checkin')->assertUnauthorized();
    }

    public function test_unfetched_commands_expire_after_a_day(): void
    {
        $this->enroll($this->code());
        $command = app(CommandService::class)->send(Device::sole(), 'reboot');
        $this->travel(25)->hours();

        $this->artisan('schedule:test', ['--name' => 'mdm:expire-commands'])->assertSuccessful();

        $this->assertSame('expired', $command->fresh()->status);
        $this->assertInstanceOf(Command::class, $command);
    }
}
