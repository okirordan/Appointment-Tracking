<?php

namespace App\Services\Mail;

use App\Models\Setting;

class MailFeatureSettings
{
    /** @var array<string, string> */
    private const FEATURES = [
        'correspondence_reference' => 'Correspondence Reference',
        'receipt_method' => 'Receipt Method',
        'confidentiality' => 'Confidentiality',
        'registry' => 'Registry',
        'initial_status' => 'Initial Status',
        'external_recipient' => 'External Recipient',
        'registry_file_number' => 'Registry File Number',
        'project_programme' => 'Project / Programme',
        'priority' => 'Priority',
        'register_number' => 'Register Number',
    ];

    /** @return array<string, string> */
    public function definitions(): array
    {
        return self::FEATURES;
    }

    /** @return array<string, bool> */
    public function all(): array
    {
        return collect(self::FEATURES)
            ->mapWithKeys(fn (string $label, string $key) => [$key => $this->enabled($key)])
            ->all();
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

        Setting::put($this->settingKey($feature), $enabled ? '1' : '0');
    }

    private function settingKey(string $feature): string
    {
        return "mail_feature_{$feature}";
    }
}
