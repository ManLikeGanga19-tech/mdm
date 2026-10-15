<?php

namespace Tests\Feature;

use App\Mdm\Jobs\SendWakePush;
use App\Mdm\Models\Device;
use App\Mdm\Models\DeviceGroup;
use App\Mdm\Models\Policy;
use App\Mdm\Services\CommandService;
use App\Mdm\Services\PushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** FCM is always faked here (CLAUDE.md rule 7): no request ever reaches Google. */
class PushServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $credentials;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $this->credentials = tempnam(sys_get_temp_dir(), 'fcm');
        file_put_contents($this->credentials, json_encode([
            'client_email' => 'mdm@noahport-mdm.iam.gserviceaccount.com',
            'private_key' => $pem,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]));
        config(['services.fcm.project_id' => 'noahport-mdm', 'services.fcm.credentials' => $this->credentials]);
    }

    protected function tearDown(): void
    {
        @unlink($this->credentials);
        parent::tearDown();
    }

    public function test_a_command_queues_one_wake_up_for_a_phone_with_a_push_token(): void
    {
        Queue::fake();
        $phone = Device::create(['asset_tag' => 'NP-0001', 'fcm_token' => 'tok-1']);
        $silent = Device::create(['asset_tag' => 'NP-0002']);

        app(CommandService::class)->send($phone, 'lock');
        app(CommandService::class)->send($silent, 'lock');

        Queue::assertPushed(SendWakePush::class, 1);
        Queue::assertPushed(SendWakePush::class, fn (SendWakePush $job) => $job->deviceId === $phone->id);
    }

    public function test_changing_group_rules_wakes_every_phone_in_the_group(): void
    {
        Queue::fake();
        $policy = Policy::create(['name' => 'P', 'payload' => ['hotspot' => 'blocked']]);
        $group = DeviceGroup::create(['name' => 'G', 'policy_id' => $policy->id]);
        Device::create(['asset_tag' => 'NP-0001', 'group_id' => $group->id, 'fcm_token' => 'a']);
        Device::create(['asset_tag' => 'NP-0002', 'group_id' => $group->id, 'fcm_token' => 'b']);
        Device::create(['asset_tag' => 'NP-0003', 'fcm_token' => 'c']); // other group

        $policy->update(['payload' => ['hotspot' => 'allowed']]);

        Queue::assertPushed(SendWakePush::class, 2);
    }

    public function test_send_uses_fcm_v1_with_a_high_priority_data_only_message(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/noahport-mdm/messages/1']),
        ]);
        $phone = Device::create(['asset_tag' => 'NP-0001', 'fcm_token' => 'tok-1']);

        app(PushService::class)->send($phone);

        Http::assertSent(fn ($request) => $request->url() === 'https://fcm.googleapis.com/v1/projects/noahport-mdm/messages:send'
            && $request->hasHeader('Authorization', 'Bearer ya29.test')
            && $request['message']['token'] === 'tok-1'
            && $request['message']['data'] === ['type' => 'checkin']
            && $request['message']['android']['priority'] === 'HIGH'
            && ! isset($request['message']['notification']));
    }

    public function test_a_dead_token_is_dropped(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test']),
            'fcm.googleapis.com/*' => Http::response(['error' => ['status' => 'UNREGISTERED']], 404),
        ]);
        $phone = Device::create(['asset_tag' => 'NP-0001', 'fcm_token' => 'old']);

        app(PushService::class)->send($phone);

        $this->assertNull($phone->fresh()->fcm_token);
    }

    public function test_without_firebase_configured_nothing_is_sent_or_queued(): void
    {
        Queue::fake();
        config(['services.fcm.project_id' => null]);

        app(PushService::class)->wake(Device::create(['asset_tag' => 'NP-0001', 'fcm_token' => 'tok']));

        Queue::assertNothingPushed();
    }
}
