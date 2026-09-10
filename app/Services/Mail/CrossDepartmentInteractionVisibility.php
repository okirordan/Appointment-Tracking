<?php

namespace App\Services\Mail;

use App\Enums\Role;
use App\Models\Correspondence;
use App\Models\CorrespondenceAttachment;
use App\Models\CorrespondenceUpdate;
use App\Models\User;
use App\Services\OrganizationalScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CrossDepartmentInteractionVisibility
{
    public function __construct(private OrganizationalScopeService $organizations) {}

    /** @return Collection<int, CorrespondenceUpdate> */
    public function visible(Correspondence $correspondence, ?User $viewer): Collection
    {
        $query = CorrespondenceUpdate::query()
            ->where('correspondence_id', $correspondence->id)
            ->where('entry_method', 'ps_cross_department')
            ->with([
                'fromOrganizationalUnit',
                'toOrganizationalUnit',
                'representedOrganizationalUnit',
                'responsibleUser',
                'attachments.uploadedBy',
            ])
            ->orderBy('occurred_at')
            ->orderBy('id');

        if ($viewer !== null) {
            $this->apply($query, $viewer);
        }

        return $query->get();
    }

    /**
     * Restrict a correspondence-update query to reconstructed interactions the
     * viewer is entitled to see. Callers remain responsible for applying the
     * entry_method predicate when they need cross-department entries only.
     *
     * @param  Builder<CorrespondenceUpdate>  $query
     * @return Builder<CorrespondenceUpdate>
     */
    public function apply(Builder $query, User $viewer): Builder
    {
        if ($this->hasPsOfficeOversight($viewer)) {
            return $query;
        }

        $unitIds = $this->organizations->unitIds($viewer);
        $departmentIds = $this->organizations->recipientDepartmentIds($viewer);

        return $query->where(function (Builder $movement) use ($viewer, $unitIds, $departmentIds) {
            $movement->where('responsible_user_id', $viewer->id);

            if ($unitIds !== []) {
                $movement->orWhereIn('from_organizational_unit_id', $unitIds)
                    ->orWhereIn('to_organizational_unit_id', $unitIds)
                    ->orWhereIn('represented_organizational_unit_id', $unitIds);
            }

            if ($departmentIds !== []) {
                $movement->orWhereHas('fromOrganizationalUnit', fn (Builder $unit) => $unit
                    ->whereIn('department_id', $departmentIds))
                    ->orWhereHas('toOrganizationalUnit', fn (Builder $unit) => $unit
                        ->whereIn('department_id', $departmentIds))
                    ->orWhereHas('representedOrganizationalUnit', fn (Builder $unit) => $unit
                        ->whereIn('department_id', $departmentIds));
            }
        });
    }

    public function canViewAttachment(CorrespondenceAttachment $attachment, User $viewer): bool
    {
        $attachment->loadMissing(['threadUpdate', 'correspondence']);
        if ($attachment->threadUpdate?->entry_method !== 'ps_cross_department') {
            return true;
        }

        return $this->visible($attachment->correspondence, $viewer)
            ->contains('id', $attachment->correspondence_update_id);
    }

    private function hasPsOfficeOversight(User $viewer): bool
    {
        if ($viewer->role === Role::Ps) {
            return true;
        }

        $viewerUnit = $this->organizations->primaryUnit($viewer);

        return $viewerUnit?->code === 'OPS'
            || $viewerUnit?->name === 'Office of the Permanent Secretary';
    }
}
