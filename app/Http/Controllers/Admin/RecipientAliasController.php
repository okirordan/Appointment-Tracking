<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Division;
use App\Models\OrganizationalUnit;
use App\Models\Position;
use App\Models\RecipientAlias;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RecipientAliasController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(): Response
    {
        $aliases = RecipientAlias::query()->with(['target', 'updatedBy:id,full_name'])->orderBy('alias')->get();
        $history = AuditLog::query()
            ->where('target_type', 'RecipientAlias')
            ->whereIn('target_id', $aliases->pluck('id'))
            ->latest('id')
            ->get()
            ->groupBy('target_id');

        return Inertia::render('admin/recipient-aliases/index', [
            'aliases' => $aliases->map(fn (RecipientAlias $alias) => [
                'id' => $alias->id,
                'alias' => $alias->alias,
                'target_type' => RecipientAlias::targetKey($alias->target_type),
                'target_id' => $alias->target_id,
                'target_label' => $this->targetLabel($alias->target),
                'active' => $alias->active,
                'updated_by' => $alias->updatedBy?->full_name ?? 'System',
                'updated_at' => $alias->updated_at?->format('d M Y, H:i'),
                'history' => $history->get($alias->id, collect())->take(10)->map(fn (AuditLog $entry) => [
                    'id' => $entry->id,
                    'action' => $entry->action,
                    'actor' => $entry->actor_name_snapshot,
                    'when' => $entry->created_at?->format('d M Y, H:i'),
                    'changes' => $entry->metadata_json,
                ])->values(),
            ]),
            'targetTypes' => collect(RecipientAlias::TARGET_TYPES)->keys()->map(fn (string $value) => [
                'value' => $value,
                'label' => match ($value) {
                    'officer' => 'Individual officer',
                    'position' => 'Position or office holder',
                    'department' => 'Department',
                    'directorate' => 'Directorate / division',
                    'unit' => 'Unit or office',
                },
            ])->values(),
            'targetOptions' => $this->targetOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        [$validated, $targetClass] = $this->validated($request);
        $alias = RecipientAlias::create([
            ...$validated,
            'target_type' => $targetClass,
            'created_by_user_id' => $request->user()->id,
            'updated_by_user_id' => $request->user()->id,
            'active' => true,
        ]);
        $this->audit->log('settings', "Created recipient shorthand {$alias->alias}", $request->user(), 'RecipientAlias', $alias->id, [
            'after' => $alias->only('alias', 'normalized_alias', 'target_type', 'target_id', 'active'),
        ]);

        return back()->with('success', 'Recipient shorthand created.');
    }

    public function update(Request $request, RecipientAlias $recipientAlias): RedirectResponse
    {
        [$validated, $targetClass] = $this->validated($request, $recipientAlias);
        $before = $recipientAlias->only('alias', 'normalized_alias', 'target_type', 'target_id', 'active');
        $recipientAlias->update([
            ...$validated,
            'target_type' => $targetClass,
            'updated_by_user_id' => $request->user()->id,
        ]);
        $this->audit->log('settings', "Updated recipient shorthand {$recipientAlias->alias}", $request->user(), 'RecipientAlias', $recipientAlias->id, [
            'before' => $before,
            'after' => $recipientAlias->only('alias', 'normalized_alias', 'target_type', 'target_id', 'active'),
        ]);

        return back()->with('success', 'Recipient shorthand updated.');
    }

    public function toggle(Request $request, RecipientAlias $recipientAlias): RedirectResponse
    {
        $before = $recipientAlias->active;
        $recipientAlias->update(['active' => ! $before, 'updated_by_user_id' => $request->user()->id]);
        $action = $recipientAlias->active ? 'Activated' : 'Deactivated';
        $this->audit->log('settings', "{$action} recipient shorthand {$recipientAlias->alias}", $request->user(), 'RecipientAlias', $recipientAlias->id, [
            'before' => ['active' => $before],
            'after' => ['active' => $recipientAlias->active],
        ]);

        return back()->with('success', "Recipient shorthand {$action}.");
    }

    /** @return array{0: array<string, mixed>, 1: class-string<Model>} */
    private function validated(Request $request, ?RecipientAlias $existing = null): array
    {
        $validated = $request->validate([
            'alias' => ['required', 'string', 'max:100', 'regex:/[A-Za-z0-9]/'],
            'target_type' => ['required', Rule::in(array_keys(RecipientAlias::TARGET_TYPES))],
            'target_id' => ['required', 'integer', 'min:1'],
        ]);
        $targetClass = RecipientAlias::targetClass($validated['target_type']);
        $target = $targetClass::query()->whereKey($validated['target_id'])->first();

        if (! $this->targetIsActive($target)) {
            throw ValidationException::withMessages(['target_id' => 'Select an active recipient, position, department, directorate, unit or office.']);
        }

        $duplicate = RecipientAlias::query()
            ->where('normalized_alias', RecipientAlias::normalize($validated['alias']))
            ->where('target_type', $targetClass)
            ->where('target_id', $validated['target_id'])
            ->when($existing !== null, fn ($query) => $query->whereKeyNot($existing->id))
            ->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['alias' => 'This shorthand already points to the selected target.']);
        }

        unset($validated['target_type']);

        return [$validated, $targetClass];
    }

    private function targetIsActive(?Model $target): bool
    {
        if ($target === null || ($target->getAttribute('active') !== null && ! $target->getAttribute('active'))) {
            return false;
        }

        return ! $target instanceof User || ! $target->locked;
    }

    /** @return array<string, Collection<int, array{id: int, label: string, meta: string|null}>> */
    private function targetOptions(): array
    {
        return [
            'officer' => User::query()->where('active', true)->where('locked', false)->with('department:id,name')->orderBy('full_name')->get()
                ->map(fn (User $user) => ['id' => $user->id, 'label' => $user->full_name, 'meta' => collect([$user->title, $user->department?->name])->filter()->join(' · ')]),
            'position' => Position::query()->where('active', true)->with('organizationalUnit:id,name')->orderBy('title')->get()
                ->map(fn (Position $position) => ['id' => $position->id, 'label' => $position->title, 'meta' => $position->organizationalUnit?->name]),
            'department' => Department::query()->where('active', true)->orderBy('name')->get()
                ->map(fn (Department $department) => ['id' => $department->id, 'label' => $department->name, 'meta' => $department->code]),
            'directorate' => Division::query()->where('active', true)->with('department:id,name')->orderBy('name')->get()
                ->map(fn (Division $division) => ['id' => $division->id, 'label' => $division->name, 'meta' => $division->department?->name]),
            'unit' => OrganizationalUnit::query()->where('active', true)->with('department:id,name')->orderBy('name')->get()
                ->map(fn (OrganizationalUnit $unit) => ['id' => $unit->id, 'label' => $unit->name, 'meta' => collect([ucfirst($unit->type), $unit->department?->name])->filter()->join(' · ')]),
        ];
    }

    private function targetLabel(?Model $target): string
    {
        return match (true) {
            $target instanceof User => collect([$target->full_name, $target->title])->filter()->join(' · '),
            $target instanceof Position => $target->title,
            $target instanceof Department, $target instanceof Division, $target instanceof OrganizationalUnit => $target->name,
            default => 'Target no longer available',
        };
    }
}
