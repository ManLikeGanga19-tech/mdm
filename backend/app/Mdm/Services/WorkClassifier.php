<?php

namespace App\Mdm\Services;

use App\Mdm\Models\WorkRule;

/**
 * Work or not, from the admin's work rules: an app rule matches its package; a domain rule matches the domain and
 * its subdomains (noahport.co.ke matches app.noahport.co.ke). Everything else is "not work".
 */
class WorkClassifier
{
    /** @var array{apps: array<string, string>, domains: array<string, string>}|null */
    private ?array $rules = null;

    public function isWorkApp(?string $package): bool
    {
        return $package !== null && isset($this->rules()['apps'][$package]);
    }

    public function isWorkHost(?string $host): bool
    {
        if (! $host) {
            return false;
        }
        $host = strtolower(rtrim($host, '.'));
        foreach (array_keys($this->rules()['domains']) as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    /** The rule's label for a work host or app, e.g. "Company PWA". */
    public function labelFor(?string $package, ?string $host): ?string
    {
        if ($package && isset($this->rules()['apps'][$package])) {
            return $this->rules()['apps'][$package];
        }
        $host = strtolower(rtrim((string) $host, '.'));
        foreach ($this->rules()['domains'] as $domain => $label) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return $label;
            }
        }

        return null;
    }

    private function rules(): array
    {
        return $this->rules ??= [
            'apps' => WorkRule::query()->where('kind', 'app')->pluck('label', 'pattern')->all(),
            'domains' => WorkRule::query()->where('kind', 'domain')->pluck('label', 'pattern')->all(),
        ];
    }
}
