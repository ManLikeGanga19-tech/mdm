<?php

namespace Tests\Feature;

use App\Filament\Pages\ShiftHandover;
use App\Filament\Resources\Crews\Pages\ManageCrews;
use App\Filament\Resources\Roster\Pages\ManageRoster;
use App\Mdm\Models\Clerk;
use App\Mdm\Models\Crew;
use App\Mdm\Models\CrewHandover;
use App\Mdm\Models\Device;
use App\Mdm\Models\DeviceGroup;
use App\Mdm\Models\Policy;
use App\Mdm\Models\Shift;
use App\Mdm\Role;
use App\Mdm\Services\CrewHandoverService;
use App\Mdm\Services\HandoverEnforcement;
use App\Mdm\Services\RosterService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/** Crews on a weekly rotating roster, and the coordinator-to-coordinator handover (shifts A 06:30, B 14:30, C 22:30 EAT). */
class CrewHandoverTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, Crew> */
    private array $crews;

    /** @var array<string, User> */
    private array $coordinators = [];

    private Device $phone1;

    private Device $phone2;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([1, 2, 3] as $n) {
            $this->crews[$n] = Crew::create(['name' => "Crew {$n}"]);
            foreach (['a', 'b'] as $x) {
                $this->coordinators["{$n}{$x}"] = User::factory()->create(['role' => Role::Coordinator, 'crew_id' => $this->crews[$n]->id, 'name' => "Coord {$n}{$x}"]);
            }
        }
        $policy = Policy::create(['name' => 'Shift phones', 'payload' => ['handover_lock' => true]]);
        $group = DeviceGroup::create(['name' => 'Shift phones', 'policy_id' => $policy->id]);
        $this->phone1 = Device::create(['asset_tag' => 'NP-0001', 'state' => Device::STATE_ENROLLED, 'group_id' => $group->id]);
        $this->phone2 = Device::create(['asset_tag' => 'NP-0002', 'state' => Device::STATE_ENROLLED, 'group_id' => $group->id]);

        // Week of Monday 19 Oct 2026: Crew 1 mornings (A), Crew 2 afternoons (B), Crew 3 nights (C).
        app(RosterService::class)->generate(Carbon::parse('2026-10-19'), 2, [
            $this->shift('A')->id => $this->crews[1]->id, $this->shift('B')->id => $this->crews[2]->id, $this->shift('C')->id => $this->crews[3]->id,
        ]);
    }

    private function shift(string $name): Shift
    {
        return Shift::query()->where('name', 'like', "%{$name}%")->orderBy('starts_at')->firstOrFail();
    }

    private function eat(string $time): Carbon
    {
        return Carbon::parse($time, 'Africa/Nairobi')->utc();
    }

    public function test_crews_move_on_one_shift_each_week(): void
    {
        $roster = app(RosterService::class);

        $this->assertSame('Crew 1', $roster->crewFor($this->shift('A'), '2026-10-21')->name);
        $this->assertSame('Crew 3', $roster->crewFor($this->shift('C'), '2026-10-25')->name);
        // Next week Crew 1 has moved to afternoons, Crew 3 from nights to mornings.
        $this->assertSame('Crew 3', $roster->crewFor($this->shift('A'), '2026-10-26')->name);
        $this->assertSame('Crew 1', $roster->crewFor($this->shift('B'), '2026-10-26')->name);
        $this->assertSame('Crew 2', $roster->crewFor($this->shift('C'), '2026-10-26')->name);
    }

    public function test_outgoing_hands_over_incoming_accepts_and_received_phones_unlock(): void
    {
        $service = app(CrewHandoverService::class);
        // 14:20 on Tue 20 Oct: Crew 1 (mornings) hands over to Crew 2 (afternoons, B from 14:30).
        $this->travelTo($this->eat('2026-10-20 14:20'));
        $context = $service->context();
        $this->assertSame('Crew 1', $context['outgoing']->name);
        $this->assertSame('Crew 2', $context['incoming']->name);

        try {
            $service->handOver($this->coordinators['2a'], $context['shift'], $context['date'], [$this->phone1->id => null]);
            $this->fail('the incoming crew handed over');
        } catch (RuntimeException) {
        }
        $handover = $service->handOver($this->coordinators['1a'], $context['shift'], $context['date'], [$this->phone1->id => 'cracked screen', $this->phone2->id => null]);
        $this->assertSame(CrewHandover::PENDING, $handover->status);
        $this->assertSame(1, $this->coordinators['2b']->notifications()->count(), 'incoming coordinators are told');

        // The window closes at 15:00 with nothing accepted: both phones lock.
        $this->travelTo($this->eat('2026-10-20 15:05'));
        $this->assertTrue(app(HandoverEnforcement::class)->isLocked($this->phone1->fresh()));
        $this->assertSame('late', $service->status($context['shift'], $context['date'], $handover->fresh()));

        $service->accept($this->coordinators['2b'], $handover->fresh(['items']), [
            $this->phone1->id => ['received' => true], $this->phone2->id => ['received' => false, 'note' => 'not in the box'],
        ]);
        $this->assertFalse(app(HandoverEnforcement::class)->isLocked($this->phone1->fresh()), 'received phone unlocks');
        $this->assertTrue(app(HandoverEnforcement::class)->isLocked($this->phone2->fresh()), 'missing phone stays locked');
        $this->assertSame('disputed', $service->status($context['shift'], $context['date'], $handover->fresh()));
        $this->assertSame(1, $this->coordinators['1a']->notifications()->where('data->title', 'Handover disputed')->count());
        $this->assertDatabaseHas('mdm_audit_log', ['action' => 'crew_handover.accepted']);
    }

    public function test_a_handover_nobody_made_is_missing_after_the_window(): void
    {
        $this->travelTo($this->eat('2026-10-20 15:10'));
        $context = app(CrewHandoverService::class)->context();

        $this->assertSame('missing', app(CrewHandoverService::class)->status($context['shift'], $context['date'], null));
    }

    public function test_my_shift_tells_the_coordinator_their_crew_shift_clerks_and_next_crew(): void
    {
        Clerk::create(['staff_number' => '2001', 'name' => 'Grace Achieng', 'crew_id' => $this->crews[2]->id]);
        Clerk::create(['staff_number' => '3001', 'name' => 'Other Crew Clerk', 'crew_id' => $this->crews[3]->id]);
        $this->travelTo($this->eat('2026-10-20 16:00'));
        $this->actingAs($this->coordinators['2a']);

        Livewire::test(ShiftHandover::class)
            ->assertSee("You're in Crew 2", false)
            ->assertSee('On duty now')
            ->assertSee('Co-coordinator: Coord 2b')
            ->assertSee('Grace Achieng')
            ->assertDontSee('Other Crew Clerk')
            ->assertSee('Crew 3 (Coord 3a, Coord 3b)');
    }

    public function test_a_super_admin_sets_up_a_crew_and_generates_the_rotation(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::SuperAdmin]));
        $newCoordinator = User::factory()->create(['role' => Role::Coordinator, 'name' => 'New Coordinator']);

        $this->get('/admin/crews')->assertOk();
        $this->get('/admin/roster')->assertOk();
        Livewire::test(ManageCrews::class)
            ->callAction('create', ['name' => 'Crew 4', 'active' => true, 'coordinator_ids' => [$newCoordinator->id, $this->coordinators['1a']->id]])
            ->assertHasNoActionErrors();
        $crew4 = Crew::where('name', 'Crew 4')->sole();
        $this->assertSame($crew4->id, $this->coordinators['1a']->fresh()->crew_id, 'moved from Crew 1');

        Livewire::test(ManageRoster::class)
            ->callAction('generate', [
                'from' => '2026-11-02', 'weeks' => 1,
                "crew_{$this->shift('A')->id}" => $crew4->id, "crew_{$this->shift('B')->id}" => $this->crews[2]->id, "crew_{$this->shift('C')->id}" => $this->crews[3]->id,
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Roster generated: 21 shifts');
        $this->assertSame('Crew 4', app(RosterService::class)->crewFor($this->shift('A'), '2026-11-05')->name);
    }

    public function test_coordinators_read_the_roster_but_cannot_manage_crews(): void
    {
        $this->actingAs($this->coordinators['1a']);

        $this->get('/admin/roster')->assertOk();
        $this->get('/admin/crews')->assertForbidden();
        Livewire::test(ManageRoster::class)->assertActionHidden('generate');
    }

    public function test_the_incoming_coordinator_accepts_on_the_page(): void
    {
        $this->travelTo($this->eat('2026-10-20 14:25'));
        $context = app(CrewHandoverService::class)->context();
        app(CrewHandoverService::class)->handOver($this->coordinators['1b'], $context['shift'], $context['date'], [$this->phone1->id => null]);
        $this->actingAs($this->coordinators['2a']);

        Livewire::test(ShiftHandover::class)
            ->assertActionVisible('acceptPhones')
            ->assertActionHidden('handOverPhones')
            ->callAction('acceptPhones')
            ->assertHasNoActionErrors()
            ->assertNotified('Phones accepted. Now give each one to a clerk below.');

        $this->assertSame(CrewHandover::ACCEPTED, CrewHandover::sole()->status);
    }
}
