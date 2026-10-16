{{-- Live console (DeviceLiveConsole). Inline styles: the admin panel's compiled CSS has no terminal classes. --}}
@php
    $colors = ['speed' => '#a3e635', 'phone' => '#fbbf24', 'server' => '#67e8f9', 'error' => '#f87171'];
    $tags = ['speed' => 'LIVE ', 'phone' => 'PHONE', 'server' => 'ADMIN', 'error' => 'ERROR'];
@endphp
<x-filament-widgets::widget>
    <x-filament::section
        heading="Live console"
        :description="$active ? 'Streaming: a line every ~2 s, plus events from the phone and from admins.' : (count($lines) ? 'Live view ended. The last lines stay here for 15 minutes.' : 'Start Live view to see this phone\'s traffic and events here as they happen.')"
        icon="heroicon-o-command-line"
    >
        <div @if ($active) wire:poll.2s @endif>
            <div
                x-data="{ stick: true }"
                x-init="
                    $el.scrollTop = $el.scrollHeight;
                    new MutationObserver(() => { if (stick) $el.scrollTop = $el.scrollHeight }).observe($el, { childList: true, subtree: true });
                "
                x-on:scroll="stick = $el.scrollTop + $el.clientHeight >= $el.scrollHeight - 30"
                style="background:#0b1020;color:#e5e7eb;font-family:ui-monospace,SFMono-Regular,Consolas,'Courier New',monospace;font-size:12.5px;line-height:1.55;border-radius:8px;padding:12px 14px;height:340px;overflow-y:auto;white-space:pre-wrap;word-break:break-word"
            >
                @forelse ($lines as $line)
                    <div wire:key="l-{{ $loop->index }}-{{ $line['at']->getTimestampMs() }}"><span style="color:#6b7280">{{ $line['at']->copy()->setTimezone($tz)->format('H:i:s') }}</span>  <span style="color:{{ $colors[$line['kind']] }}">{{ $tags[$line['kind']] }}</span>  {{ $line['text'] }}</div>
                @empty
                    <div style="color:#6b7280">$ waiting for live view…</div>
                @endforelse
                @if ($active)
                    <div style="color:#6b7280">▌</div>
                @endif
            </div>
            <div style="margin-top:6px;font-size:12px;color:#6b7280">
                LIVE: speed, network, signal and the apps using the most data (Android refreshes per-app counters on its own schedule; "apps Ns ago" shows their age).
                PHONE: events on the phone. ADMIN: what admins and the server did. Website names come in batches on the Website log tab.
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
