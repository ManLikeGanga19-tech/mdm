<?php

namespace App\Mdm\Services;

use Illuminate\Database\Eloquent\Builder;

/**
 * Phone and browser housekeeping that nobody "visited": Play Store and Google services updates, connectivity checks,
 * ads/analytics beacons, the MDM agent talking to this server, Android system lookups. Hidden by default from website
 * lists so what the clerk actually opened is visible. A work rule always wins (a work host is never background).
 */
class BackgroundTraffic
{
    /** A host matches a domain itself or any subdomain of it. */
    private const DOMAINS = [
        'googleapis.com', 'gstatic.com', 'gvt1.com', 'gvt2.com', 'gvt3.com', 'googleusercontent.com', 'ggpht.com',
        'ytimg.com', 'googleadservices.com', 'googlesyndication.com', 'googletagmanager.com', 'google-analytics.com',
        'doubleclick.net', 'app-measurement.com', 'crashlytics.com', 'firebaseio.com', 'fastly-edge.com',
        'ampproject.org', 'connectivitycheck.android.com', 'mtalk.google.com', 'accounts.google.com',
        'play.google.com', 'time.google.com', 'android.apis.google.com', 'clients.google.com', 'clients1.google.com',
        'clients2.google.com', 'clients3.google.com', 'clients4.google.com', 'clients5.google.com',
        'clients6.google.com', 'localhost',
    ];

    /** Apps (or Android's shared/system UIDs, by prefix) whose lookups are all background. */
    private const PACKAGES = ['com.noahport.mdm.agent', 'android.uid.', 'com.google.uid.shared', 'com.google.android.gms'];

    public function __construct(private WorkClassifier $work) {}

    public function isBackground(?string $host, ?string $package): bool
    {
        if ($this->work->isWorkHost($host) || $this->work->isWorkApp($package)) {
            return false;
        }
        foreach (self::PACKAGES as $prefix) {
            if ($package !== null && str_starts_with($package, $prefix)) {
                return true;
            }
        }
        $host = strtolower(rtrim((string) $host, '.'));
        foreach ($this->domains() as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    /** Limits a network-log query to lookups that are NOT background (approximate in SQL: work rules aren't applied). */
    public function excludeFrom(Builder $query): Builder
    {
        foreach (self::PACKAGES as $prefix) {
            $query->whereRaw("coalesce(package_name, '') not like ?", [$prefix.'%']);
        }
        foreach ($this->domains() as $domain) {
            $query->where('host', '!=', $domain)->where('host', 'not like', '%.'.$domain);
        }

        return $query;
    }

    /** @return list<string> */
    private function domains(): array
    {
        $self = parse_url((string) config('app.url'), PHP_URL_HOST);

        return $self ? [...self::DOMAINS, strtolower($self)] : self::DOMAINS;
    }
}
