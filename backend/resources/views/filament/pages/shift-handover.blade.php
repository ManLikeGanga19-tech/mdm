{{-- My shift (App\Filament\Pages\ShiftHandover). Layout kept to a single column so it reads well on a phone. --}}
@php
    $fmt = fn ($c) => $c->copy()->setTimezone($tz)->format('H:i');
    $day = function ($date) use ($tz) {
        $d = \Illuminate\Support\Carbon::parse($date, $tz);
        return $d->isToday() ? 'today' : ($d->isTomorrow() ? 'tomorrow' : ($d->isYesterday() ? 'yesterday' : $d->format('D j M')));
    };
    $statusColor = ['on time' => 'success', 'pending' => 'warning', 'waiting' => 'gray', 'late' => 'warning', 'missing' => 'danger', 'disputed' => 'danger'];
@endphp
<x-filament-panels::page>
    <div wire:poll.15s style="display:grid;gap:16px">
        {{-- Who and when --}}
        <x-filament::section icon="heroicon-o-user-group">
            <x-slot name="heading">
                @if ($crew)
                    You're in {{ $crew->name }}
                @else
                    Shift overview
                @endif
            </x-slot>
            @if ($crew && $duty)
                <p style="font-size:1.05rem;margin:0">
                    @if ($duty['on_duty'])
                        <x-filament::badge color="success" style="display:inline-flex">On duty now</x-filament::badge>
                        <strong>{{ $duty['shift']->name }}</strong> {{ $fmt($duty['start']) }}–{{ $fmt($duty['end']) }}, ends {{ $duty['end']->diffForHumans() }}
                    @else
                        <x-filament::badge color="gray" style="display:inline-flex">Off duty</x-filament::badge>
                        Next: <strong>{{ $duty['shift']->name }}</strong> {{ $day($duty['date']) }} {{ $fmt($duty['start']) }}–{{ $fmt($duty['end']) }} ({{ $duty['start']->diffForHumans() }})
                    @endif
                </p>
                @if ($partners)
                    <p class="text-sm text-gray-500 dark:text-gray-400" style="margin:8px 0 0">Co-coordinator: {{ implode(', ', $partners) }}</p>
                @endif
            @elseif ($crew)
                <p class="text-sm text-gray-500 dark:text-gray-400" style="margin:0">{{ $crew->name }} has no shifts on the roster in the next two weeks. Ask IT to generate the roster.</p>
            @elseif ($context)
                <p style="margin:0"><strong>{{ $context['shift']->name }}</strong> {{ $day($context['date']) }}: {{ $context['incoming']?->labelWithCoordinators() ?? 'no crew on the roster' }}</p>
                <p class="text-sm text-gray-500 dark:text-gray-400" style="margin:8px 0 0">You are not in a crew, so you see every crew's handover and can act for either side.</p>
            @else
                <p class="text-sm text-gray-500 dark:text-gray-400" style="margin:0">No shifts set up yet.</p>
            @endif
        </x-filament::section>

        {{-- The handover that matters now --}}
        @if ($context)
            <x-filament::section icon="heroicon-o-arrows-right-left">
                <x-slot name="heading">
                    {{ $context['shift']->name }} handover · {{ $fmt($context['start']) }}–{{ $fmt($context['window_end']) }} {{ $day($context['date']) }}
                </x-slot>
                <x-slot name="afterHeader">
                    <x-filament::badge :color="$statusColor[$status] ?? 'gray'">{{ ucfirst($status) }}</x-filament::badge>
                </x-slot>
                <p style="margin:0 0 6px">
                    <strong>{{ $context['outgoing']?->labelWithCoordinators() ?? 'Previous crew' }}</strong>
                    →
                    <strong>{{ $context['incoming']?->labelWithCoordinators() ?? 'no crew on the roster' }}</strong>
                </p>
                @if ($line = \App\Filament\Pages\ShiftHandover::handoverLine($context['handover'], $tz))
                    <p class="text-sm text-gray-500 dark:text-gray-400" style="margin:0 0 6px">{{ $line }}</p>
                @endif
                @if ($context['handover'] && $context['handover']->status === 'disputed')
                    <p class="text-sm" style="margin:0 0 6px;color:#dc2626">
                        Not received: {{ $context['handover']->items->where('received', false)->map(fn ($i) => $i->device->asset_tag.($i->receive_note ? " ({$i->receive_note})" : ''))->implode(', ') }}
                    </p>
                @endif
                <p class="text-sm text-gray-500 dark:text-gray-400" style="margin:0 0 12px">
                    @switch($side)
                        @case('outgoing')
                            Your crew is handing over. Tap the button, check the phones and add notes.
                            @break
                        @case('incoming')
                            {{ $context['handover']?->isPending() ? 'The phones are waiting for you: check them and accept.' : ($context['handover'] ? 'Done. Give each phone to a clerk below.' : 'Waiting for the outgoing crew to hand the phones over.') }}
                            @break
                        @case('admin')
                            You can hand over or accept for the crews.
                            @break
                        @default
                            Your crew is not part of this handover.
                    @endswitch
                </p>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    {{ $this->handOverPhonesAction }}
                    {{ $this->acceptPhonesAction }}
                </div>
            </x-filament::section>
        @endif

        {{-- My clerks and the next crew --}}
        @if ($crew)
            <x-filament::section icon="heroicon-o-identification" collapsible>
                <x-slot name="heading">{{ $crew->name }} clerks ({{ $clerks->count() }})</x-slot>
                @forelse ($clerks as $clerk)
                    <div style="display:flex;justify-content:space-between;gap:8px;padding:6px 0;border-bottom:1px solid rgba(148,163,184,.25)">
                        <span>{{ $clerk->name }} <span class="text-sm text-gray-500 dark:text-gray-400">{{ $clerk->staff_number }}</span></span>
                        <span class="text-sm">{{ $clerk->currentAssignment?->device?->asset_tag ?? '—' }}</span>
                    </div>
                @empty
                    <p class="text-sm text-gray-500 dark:text-gray-400" style="margin:0">No clerks in your crew yet. IT adds them on Clerks.</p>
                @endforelse
            </x-filament::section>

            @if ($next)
                <x-filament::section icon="heroicon-o-forward" compact>
                    <x-slot name="heading">After your shift</x-slot>
                    <p style="margin:0">
                        <strong>{{ $next['shift']->name }}</strong> from {{ \Illuminate\Support\Carbon::parse($next['shift']->window($next['date'])[0])->setTimezone($tz)->format('H:i') }} {{ $day($next['date']) }}:
                        {{ $next['crew']?->labelWithCoordinators() ?? 'no crew on the roster' }}
                    </p>
                </x-filament::section>
            @endif
        @endif

        {{ $this->table }}
    </div>

</x-filament-panels::page>
