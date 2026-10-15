<?php

namespace Tests\Feature;

use App\Mdm\Models\AppInventory;
use App\Mdm\Models\Device;
use App\Mdm\Models\DeviceCredential;
use App\Mdm\Models\IngestBatch;
use App\Mdm\Models\NetworkLog;
use App\Mdm\Models\StatusReport;
use App\Mdm\Models\UsageSample;
use App\Mdm\Services\EnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Telemetry uploads: fast, idempotent on batch_uuid, processed by a job (queue is sync in tests). */
class IngestTest extends TestCase
{
    use RefreshDatabase;

    private Device $device;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->device = Device::create(['asset_tag' => 'NP-0001', 'state' => Device::STATE_ENROLLED]);
        $this->token = Str::random(48);
        DeviceCredential::create(['device_id' => $this->device->id, 'token_hash' => EnrollmentService::hash($this->token), 'created_at' => now()]);
    }

    private function upload(string $path, array $body, ?string $uuid = null)
    {
        return $this->withToken($this->token)->postJson("/api/device/v1/{$path}", ['batch_uuid' => $uuid ?? (string) Str::uuid()] + $body);
    }

    private function usageSample(string $package, int $rx, string $start = '2026-10-16T05:00:00Z'): array
    {
        return ['package' => $package, 'network' => 'mobile', 'rx' => $rx, 'tx' => 100, 'start' => $start, 'end' => '2026-10-16T06:00:00Z'];
    }

    public function test_usage_is_stored_per_app_per_hour_and_a_resent_hour_replaces_it(): void
    {
        $this->upload('usage', ['samples' => [$this->usageSample('com.android.chrome', 5000), $this->usageSample('__tethering__', 9000)]])
            ->assertAccepted();
        $this->upload('usage', ['samples' => [$this->usageSample('com.android.chrome', 7000)]])->assertAccepted();

        $this->assertSame(2, UsageSample::count());
        $this->assertSame(7000, UsageSample::where('package_name', 'com.android.chrome')->sole()->rx_bytes);
        $this->assertSame(9000, UsageSample::where('package_name', '__tethering__')->sole()->rx_bytes);
        $this->assertNotNull(IngestBatch::first()->processed_at);
    }

    public function test_a_repeated_batch_is_ignored(): void
    {
        $uuid = (string) Str::uuid();
        $events = ['events' => [['type' => 'dns', 'package' => 'com.android.chrome', 'host' => 'WWW.BBC.COM', 'at' => '2026-10-16T05:10:00Z']]];

        $this->upload('network-logs', $events, $uuid)->assertAccepted();
        $this->upload('network-logs', $events, $uuid)->assertOk()->assertJson(['status' => 'duplicate']);

        $this->assertSame(1, NetworkLog::count());
        $this->assertSame('www.bbc.com', NetworkLog::sole()->host);
    }

    public function test_inventory_replaces_the_app_list(): void
    {
        $app = fn ($p, $l) => ['package' => $p, 'label' => $l, 'version_name' => '1.0', 'version_code' => 1, 'system' => false];
        $this->upload('inventory', ['apps' => [$app('com.android.chrome', 'Chrome'), $app('com.zhiliaoapp.musically', 'TikTok')]])->assertAccepted();
        $this->travel(1)->minutes();
        $this->upload('inventory', ['apps' => [$app('com.android.chrome', 'Chrome')]])->assertAccepted();

        $this->assertSame(['com.android.chrome'], AppInventory::pluck('package_name')->all());
    }

    public function test_bad_uploads_are_refused(): void
    {
        $this->upload('usage', ['samples' => [['package' => 'x', 'network' => 'bluetooth', 'rx' => -1]]])->assertUnprocessable();
        $this->withoutToken()->postJson('/api/device/v1/usage', ['batch_uuid' => (string) Str::uuid(), 'samples' => []])->assertUnauthorized();
        $this->assertSame(0, IngestBatch::count());
    }

    public function test_each_checkin_adds_battery_history(): void
    {
        $this->withToken($this->token)->postJson('/api/device/v1/checkin', ['battery_percent' => 80, 'network_type' => 'mobile'])->assertOk();
        $this->withToken($this->token)->postJson('/api/device/v1/checkin', ['battery_percent' => 76, 'battery_charging' => false])->assertOk();

        $this->assertSame([80, 76], StatusReport::orderBy('id')->pluck('battery_percent')->all());
    }

    public function test_old_telemetry_is_pruned_after_the_retention_period(): void
    {
        NetworkLog::create(['device_id' => $this->device->id, 'log_type' => 'dns', 'host' => 'old.example', 'occurred_at' => now()->subDays(91)]);
        NetworkLog::create(['device_id' => $this->device->id, 'log_type' => 'dns', 'host' => 'new.example', 'occurred_at' => now()->subDays(5)]);

        $this->artisan('schedule:test', ['--name' => 'mdm:prune-telemetry'])->assertSuccessful();

        $this->assertSame(['new.example'], NetworkLog::pluck('host')->all());
    }
}
