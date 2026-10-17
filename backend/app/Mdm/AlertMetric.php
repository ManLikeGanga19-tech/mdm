<?php

namespace App\Mdm;

/** What an alert rule watches. AlertService works each one out; the threshold's unit depends on the metric. */
enum AlertMetric: string
{
    case Offline = 'offline';
    case HotspotData = 'hotspot_data';
    case MobileData = 'mobile_data';
    case BatteryLow = 'battery_low';
    case HandoverLock = 'handover_lock';
    case HandoverMissed = 'handover_missed';

    public function label(): string
    {
        return match ($this) {
            self::Offline => 'Phone offline',
            self::HotspotData => 'Hotspot data over a limit',
            self::MobileData => 'Mobile data over a limit (incl. hotspot)',
            self::BatteryLow => 'Battery low during a shift',
            self::HandoverLock => 'Phone stuck on the handover lock',
            self::HandoverMissed => 'Crew handover missed, not accepted or disputed',
        };
    }

    /** Unit of the threshold, shown next to it. */
    public function unit(): string
    {
        return match ($this) {
            self::HotspotData, self::MobileData => 'MB',
            self::BatteryLow => '%',
            default => 'minutes',
        };
    }

    public function help(): string
    {
        return match ($this) {
            self::Offline => 'No check-in for this many minutes (phones normally check in every ~15 minutes).',
            self::HotspotData => 'A phone shared more than this through its hotspot in the day or week.',
            self::MobileData => 'A phone used more than this on mobile data (SIM) in the day or week.',
            self::BatteryLow => 'At or below this level and not charging, while a shift is running.',
            self::HandoverLock => 'Locked for this many minutes because nobody recorded its handover.',
            self::HandoverMissed => 'This many minutes after the handover window closes: nothing handed over, not accepted, or phones not received.',
        };
    }

    /** Day or week totals (the window resets at midnight / Monday in the admin's time zone). */
    public function usesWindow(): bool
    {
        return in_array($this, [self::HotspotData, self::MobileData], true);
    }

    /** A condition that can clear by itself (then the alert is resolved and may be raised again later). */
    public function isCondition(): bool
    {
        return in_array($this, [self::Offline, self::BatteryLow, self::HandoverLock], true);
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $m) => [$m->value => $m->label()])->all();
    }
}
