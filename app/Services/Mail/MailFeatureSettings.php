<?php

namespace App\Services\Mail;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class MailFeatureSettings
{
    private const DISPLAY_KEY = 'ats:mail:feature-display:v1';

    private const VERSION_KEY = 'ats:mail:feature-display-version:v1';

    /** @var array<string, string> */
    private const FEATURES = [
        'correspondence_reference' => 'Correspondence Reference',
        'receipt_method' => 'Receipt Method',
        'confidentiality' => 'Confidentiality',
        'registry' => 'Registry',
        'initial_status' => 'Initial Status',
        'registry_file_number' => 'Registry File Number',
        'project_programme' => 'Project / Programme',
        'priority' => 'Priority',
        'register_number' => 'Register Number',
        'forwarding_due_date' => 'Forwarding Due Date',
        'ps_office_cross_department_recording' => 'Enable PS Office Cross-Department Recording',
    ];

    /** @return array<string, string> */
    public function definitions(): array
    {
        return self::FEATURES;
    }

    /** @return array<string, bool> */
    public function all(): array
    {
        // The shared version propagates edits across nodes; the short file
        // cache holds only non-user-specific display configuration.
        return Cache::store('file')->remember($this->displayCacheKey(), 30, fn () => collect(self::FEATURES)
            ->mapWithKeys(fn (string $label, string $key) => [$key => $this->enabled($key)])
            ->all());
    }

    public function enabled(string $feature): bool
    {
        if (! array_key_exists($feature, self::FEATURES)) {
            return false;
        }

        return Setting::value($this->settingKey($feature), '0') === '1';
    }

    public function set(string $feature, bool $enabled): void
    {
        if (! array_key_exists($feature, self::FEATURES)) {
            return;
        }

        $oldKey = $this->displayCacheKey();
        Setting::put($this->settingKey($feature), $enabled ? '1' : '0');
        Cache::forever(self::VERSION_KEY, (string) Str::uuid());
        Cache::store('file')->forget($oldKey);
    }

    private function settingKey(string $feature): string
    {
        return "mail_feature_{$feature}";
    }

    private function displayCacheKey(): string
    {
        return self::DISPLAY_KEY.':'.Cache::rememberForever(self::VERSION_KEY, fn () => (string) Str::uuid());
    }
}
