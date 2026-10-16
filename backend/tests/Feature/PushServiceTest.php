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
            && $request['message']['data']['type'] === 'checkin'
            && filled($request['message']['data']['id'])
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

    private function enableRealtime(): void
    {
        config([
            'app.url' => 'https://mdm.test',
            'services.mercure.public_url' => 'https://mdm.test/.well-known/mercure',
            'services.mercure.internal_url' => 'http://app/.well-known/mercure',
            'services.mercure.issuer' => 'noahport-mdm',
            'services.mercure.publisher_key' => 'publisher-key-publisher-key-0123',
            'services.mercure.subscriber_key' => 'subscriber-key-subscriber-key-01',
        ]);
    }

    /** @return array{0: array, 1: array} header and claims of a JWT whose HS256 signature matches $key */
    private function decodeJwt(string $jwt, string $key): array
    {
        [$h, $c, $s] = explode('.', $jwt);
        $this->assertSame(rtrim(strtr(base64_encode(hash_hmac('sha256', "$h.$c", $key, true)), '+/', '-_'), '='), $s);
        $decode = fn ($part) => json_decode(base64_decode(strtr($part, '-_', '+/')), true);

        return [$decode($h), $decode($c)];
    }

    public function test_send_publishes_a_private_wake_up_on_the_phones_topic_with_the_same_id_as_fcm(): void
    {
        $this->enableRealtime();
        Http::fake([
            'app/.well-known/mercure' => Http::response('urn:uuid:1'),
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test']),
            'fcm.googleapis.com/*' => Http::response(['name' => 'm/1']),
        ]);
        $phone = Device::create(['asset_tag' => 'NP-0001', 'fcm_token' => 'tok-1']);
        $topic = 'https://mdm.test/devices/'.$phone->uuid;

        app(PushService::class)->send($phone);

        $hub = Http::recorded(fn ($r) => $r->url() === 'http://app/.well-known/mercure')->first()[0];
        $fcm = Http::recorded(fn ($r) => str_contains($r->url(), 'fcm.googleapis.com'))->first()[0];
        $this->assertSame($topic, $hub['topic']);
        $this->assertSame('on', $hub['private']);
        $data = json_decode($hub['data'], true);
        $this->assertSame('checkin', $data['type']);
        $this->assertSame($data['id'], $fcm['message']['data']['id']);

        [$header, $claims] = $this->decodeJwt(substr($hub->header('Authorization')[0], 7), 'publisher-key-publisher-key-0123');
        $this->assertSame('at+jwt', $header['typ']);
        $this->assertSame('https://mdm.test/.well-known/mercure', $claims['aud']);
        $this->assertSame(['publish'], $claims['authorization_details'][0]['actions']);
        $this->assertSame([['match' => $topic]], $claims['authorization_details'][0]['topics']);
    }

    public function test_a_hub_failure_still_sends_the_firebase_push(): void
    {
        $this->enableRealtime();
        Http::fake([
            'app/.well-known/mercure' => Http::response('down', 502),
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test']),
            'fcm.googleapis.com/*' => Http::response(['name' => 'm/1']),
        ]);

        app(PushService::class)->send(Device::create(['asset_tag' => 'NP-0001', 'fcm_token' => 'tok-1']));

        Http::assertSent(fn ($r) => str_contains($r->url(), 'fcm.googleapis.com'));
    }

    public function test_a_phone_gets_a_token_to_listen_to_its_own_topic_only(): void
    {
        $phone = Device::create(['asset_tag' => 'NP-0001']);
        $this->assertNull(app(PushService::class)->realtimeFor($phone), 'off until configured');

        $this->enableRealtime();
        $realtime = app(PushService::class)->realtimeFor($phone);

        $this->assertSame('https://mdm.test/.well-known/mercure', $realtime['hub_url']);
        $this->assertSame('https://mdm.test/devices/'.$phone->uuid, $realtime['topic']);
        [, $claims] = $this->decodeJwt($realtime['token'], 'subscriber-key-subscriber-key-01');
        $this->assertSame('noahport-mdm', $claims['iss']);
        $this->assertGreaterThan(now()->addDays(29)->timestamp, $claims['exp']);
        $this->assertSame(['subscribe'], $claims['authorization_details'][0]['actions']);
        $this->assertSame([['match' => $realtime['topic']]], $claims['authorization_details'][0]['topics']);
    }

    public function test_a_wake_up_without_firebase_still_goes_out_over_the_real_time_channel(): void
    {
        Queue::fake();
        config(['services.fcm.project_id' => null]);
        $this->enableRealtime();

        app(PushService::class)->wake(Device::create(['asset_tag' => 'NP-0001']));

        Queue::assertPushed(SendWakePush::class, 1);
    }

    public function test_the_next_check_in_records_how_long_the_phone_took_to_answer(): void
    {
        Queue::fake();
        $phone = Device::create(['asset_tag' => 'NP-0001', 'fcm_token' => 'tok-1']);
        $push = app(PushService::class);

        $this->freezeTime();
        $push->wake($phone);
        $this->travel(2)->seconds();
        $push->wake($phone); // a second click before the phone answered: timed from the first
        $this->travel(1)->seconds();
        $push->checkedIn($phone);

        $this->assertSame(3.0, $push->wakeLatency($phone)['seconds']);
        $push->checkedIn($phone); // a periodic check-in with no wake-up pending changes nothing
        $this->assertSame(3.0, $push->wakeLatency($phone)['seconds']);
    }

    public function test_without_firebase_configured_nothing_is_sent_or_queued(): void
    {
        Queue::fake();
        config(['services.fcm.project_id' => null]);

        app(PushService::class)->wake(Device::create(['asset_tag' => 'NP-0001', 'fcm_token' => 'tok']));

        Queue::assertNothingPushed();
    }
}
