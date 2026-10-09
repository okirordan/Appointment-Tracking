<?php

namespace App\Services\Mail;

use App\Models\Department;
use App\Models\OrganizationalUnit;

/** Resolves stored office and title names to their current ministry shorthand for display. */
class MinistryShorthand
{
    /** @var array<string, string|null>|null */
    private ?array $officeLabels = null;

    public function __construct(
        private MailPartyDisplay $parties,
        private OrganizationalRoutingLabel $routing,
    ) {}

    public function label(?string $name): ?string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        $title = $this->parties->officialTitleFor($name);
        if ($title !== null) {
            return $this->psLabel($title);
        }

        $office = $this->officeLabels()[$this->key($name)] ?? null;

        return $office === null ? $this->psLabel($name) : $this->psLabel($office);
    }

    public function unit(?OrganizationalUnit $unit): ?string
    {
        if ($unit === null) {
            return null;
        }

        $short = $this->routing->for($unit);

        return str_starts_with($short, 'ORG-')
            ? $this->label($this->routing->headLabel($unit))
            : $this->psLabel($short);
    }

    /** @return array<string, string|null> */
    private function officeLabels(): array
    {
        if ($this->officeLabels !== null) {
            return $this->officeLabels;
        }

        $labels = [];
        foreach (OrganizationalUnit::query()->with(['department', 'parent.department'])->get() as $unit) {
            $short = $this->routing->for($unit);
            if (str_starts_with($short, 'ORG-')) {
                continue;
            }
            $this->put($labels, $unit->name, $short);
            $this->put($labels, $this->routing->headLabel($unit), $short);
            $this->put($labels, $short, $short);
        }
        foreach (Department::query()->get(['code', 'name']) as $department) {
            if (! array_key_exists($this->key($department->name), $labels)) {
                $this->put($labels, $department->name, $department->code);
            }
        }

        return $this->officeLabels = $labels;
    }

    /** @param array<string, string|null> $labels */
    private function put(array &$labels, ?string $name, ?string $short): void
    {
        if (blank($name) || blank($short)) {
            return;
        }
        $key = $this->key($name);
        if (array_key_exists($key, $labels) && $labels[$key] !== $short) {
            $labels[$key] = null;

            return;
        }
        $labels[$key] = $short;
    }

    private function key(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($name)) ?? trim($name));
    }

    private function psLabel(string $label): string
    {
        return $label === 'PS' ? 'PS/ES' : $label;
    }
}
