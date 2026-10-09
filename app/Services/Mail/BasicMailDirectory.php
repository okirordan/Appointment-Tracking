<?php

namespace App\Services\Mail;

use App\Models\AnnotationTitle;
use App\Models\CorrespondenceOfficeAlias;
use App\Models\CorrespondenceUpdate;
use App\Models\Department;
use App\Models\ExternalMailSource;
use App\Models\MailNamedOfficer;
use App\Models\MailRecord;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Directory identities are kept separate from display labels. */
class BasicMailDirectory
{
    public function __construct(private RecipientSearchService $recipients, private CorrespondenceOfficeDirectory $offices) {}

    public function search(User $actor, string $term, bool $source = false): Collection
    {
        $like = '%'.addcslashes(trim($term), '%_\\').'%';
        $titles = AnnotationTitle::where('active', true)->where(fn ($q) => $q
            ->where('shorthand', 'like', $like)->orWhere('full_title', 'like', $like))
            ->orderBy('shorthand')->limit(12)->get()->map(fn ($title) => [
                'key' => 'title:'.$title->id, 'value' => $title->shorthand ?: $title->full_title,
                'label' => $title->shorthand.' — '.$title->full_title, 'kind' => 'Title',
            ]);
        $departments = Department::where('active', true)->where(fn ($q) => $q
            ->where('code', 'like', $like)->orWhere('name', 'like', $like))
            ->orderBy('name')->limit(12)->get()->map(fn ($department) => [
                'key' => 'department:'.$department->id, 'value' => $department->code ?: $department->name,
                'label' => $department->code.' — '.$department->name, 'kind' => 'Department',
            ]);
        $users = collect($this->recipients->search($actor, $term, 12, false, null, false))
            ->map(fn ($user) => [
                'key' => 'user:'.$user['id'], 'value' => $user['title_shorthand'] ?: $user['name'],
                'label' => $user['name'].' — '.($user['title_shorthand'] ?: $user['title']), 'kind' => 'Officer',
            ]);

        $external = $source ? ExternalMailSource::where('name', 'like', $like)->orderBy('name')->limit(12)->get()
            ->map(fn ($entry) => ['label' => $entry->name, 'value' => $entry->name, 'key' => 'external:'.$entry->id, 'kind' => ucfirst($entry->kind)]) : collect();

        return $titles->concat($departments)->concat($users)->concat($external)
            ->concat($source ? collect() : CorrespondenceOfficeAlias::where('name', 'like', $like)->orderBy('name')->limit(12)->get()
                ->map(fn ($office) => ['label' => $office->name, 'value' => $office->name, 'key' => 'recipient:'.$office->id, 'kind' => ucfirst($office->kind)]))->values();
    }

    public function resolve(?string $key, string $field): AnnotationTitle|Department|User|MailNamedOfficer|ExternalMailSource|CorrespondenceOfficeAlias|null
    {
        if (blank($key) || in_array($key, ['new', 'new-recipient'], true)) {
            return null;
        }
        [$kind, $id] = array_pad(explode(':', $key, 2), 2, '');
        $query = match ($kind) {
            'title' => AnnotationTitle::query(), 'department' => Department::query(),
            'user' => User::where('locked', false), 'named' => MailNamedOfficer::query(),
            'external' => ExternalMailSource::query(), 'recipient' => CorrespondenceOfficeAlias::query(), default => null,
        };
        $model = $query && ctype_digit($id) ? $query->when(! in_array($kind, ['named', 'external', 'recipient'], true), fn ($q) => $q->where('active', true))->find($id) : null;
        if (! $model) {
            throw ValidationException::withMessages([$field => 'Select a current directory entry or enter a custom office.']);
        }

        return $model;
    }

    public function name(AnnotationTitle|Department|User|MailNamedOfficer|ExternalMailSource|CorrespondenceOfficeAlias $party): string
    {
        return match (true) {
            $party instanceof AnnotationTitle => $party->full_title,
            $party instanceof Department => $party->name,
            $party instanceof ExternalMailSource => $party->name,
            $party instanceof CorrespondenceOfficeAlias => $party->name,
            default => $party->full_name,
        };
    }

    public function rememberSource(string $name, string $kind, User $actor): ExternalMailSource
    {
        $name = ExternalMailSource::displayName($name);
        $normalized = ExternalMailSource::normalize($name);
        if ($normalized === '') {
            throw ValidationException::withMessages(['sender_name' => 'Enter a source name.']);
        }

        return ExternalMailSource::firstOrCreate(
            ['normalized_name' => $normalized],
            ['name' => $name, 'kind' => $kind, 'created_by_user_id' => $actor->id],
        );
    }

    public function mailAttributes(array $data, User $actor, bool $incoming): array
    {
        $attributes = [];
        foreach (['source', 'recipient'] as $side) {
            $key = 'basic_'.$side.'_key';
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $party = $this->resolve($data[$key], $key);
            $source = $side === 'source';
            if ($source && $incoming && $party === null) {
                $party = $this->rememberSource($data['sender_name'], $data['basic_source_kind'] ?? 'organization', $actor);
            }
            $name = $party ? $this->name($party) : trim($data[$source ? 'sender_name' : 'recipient_name']);
            $internal = $party !== null && ! $party instanceof ExternalMailSource && ! $party instanceof CorrespondenceOfficeAlias;
            $attributes += [
                $source ? 'sender_name' : 'recipient_name' => $name,
                $source ? 'source_type' : 'destination_type' => $internal ? 'internal' : 'external',
                $source ? 'annotation_title_id' : 'recipient_annotation_title_id' => $party instanceof AnnotationTitle ? $party->id : null,
                $source ? 'source_staff_user_id' : 'recipient_staff_user_id' => $party instanceof User ? $party->id : null,
                $side.'_department_id' => $party instanceof Department ? $party->id : null,
            ];
            $attributes += $source ? [
                'external_source' => $internal ? null : $name,
                'external_source_id' => $party instanceof ExternalMailSource ? $party->id : null,
            ] : ['recipient_named_officer_id' => $party instanceof MailNamedOfficer ? $party->id : null];
        }

        return $attributes;
    }

    public function correspondenceAttributes(array $data, User $actor): array
    {
        $party = $this->resolve($data['destination_key'] ?? null, 'destination_key');
        if ($party === null) {
            $party = $this->offices->rememberRecipient($data['destination_office_snapshot'], $actor, $data['destination_kind'] ?? 'office');
        }

        return [
            'destination_office_snapshot' => $this->name($party),
            'destination_annotation_title_id' => $party instanceof AnnotationTitle ? $party->id : null,
            'destination_user_id' => $party instanceof User ? $party->id : null,
            'destination_department_id' => $party instanceof Department ? $party->id : null,
            'destination_office_alias_id' => $party instanceof CorrespondenceOfficeAlias ? $party->id : null,
        ];
    }

    public function correspondenceDisplay(CorrespondenceUpdate $entry): ?string
    {
        return $entry->destinationTitle?->shorthand
            ?: ($entry->destinationUser ? ($this->recipients->titleShorthand($entry->destinationUser) ?: $entry->destinationUser->full_name) : null)
            ?: $entry->destinationDepartment?->code ?: $entry->destinationDepartment?->name
            ?: app(MailPartyDisplay::class)->officialTitleFor($entry->destination_office_snapshot ?? '')
            ?: $entry->destination_office_snapshot;
    }

    public function key(MailRecord $mail, bool $source): string
    {
        if ($source && $mail->external_source_id) {
            return 'external:'.$mail->external_source_id;
        }
        foreach (['title' => $source ? $mail->annotation_title_id : $mail->recipient_annotation_title_id,
            'user' => $source ? $mail->source_staff_user_id : $mail->recipient_staff_user_id,
            'department' => $source ? $mail->source_department_id : $mail->recipient_department_id,
            'named' => $source ? null : $mail->recipient_named_officer_id] as $kind => $id) {
            if ($id) {
                return $kind.':'.$id;
            }
        }

        return '';
    }

    /** Labels for the existing snapshot-based register filter; filter values stay unchanged. */
    public function snapshotLabels(array $names): array
    {
        $departments = Department::whereIn('name', $names)->get()->keyBy('name');
        $users = User::whereIn('full_name', $names)->get()->keyBy('full_name');
        $display = app(MailPartyDisplay::class);

        return collect($names)->mapWithKeys(fn ($name) => [$name => $display->officialTitleFor($name)
            ?: ($departments->get($name)?->code ?: null)
            ?: ($users->has($name) ? $this->recipients->titleShorthand($users->get($name)) : null)
            ?: $name,
        ])->all();
    }
}
