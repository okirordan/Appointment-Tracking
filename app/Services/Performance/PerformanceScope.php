<?php

namespace App\Services\Performance;

use App\Enums\Role;
use App\Models\Department;
use App\Models\Division;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class PerformanceScope
{
    public function departments(User $viewer): Builder
    {
        $query = Department::query();
        if (in_array($viewer->role, [Role::Commissioner, Role::Secretary], true)) {
            $query->whereKey($viewer->department_id);
        } elseif (! in_array($viewer->role, [Role::Sysadmin, Role::Ps], true)) {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    public function canViewDepartment(User $viewer, Department $department): bool
    {
        return $this->departments($viewer)->whereKey($department)->exists();
    }

    public function canViewDivision(User $viewer, Division $division): bool
    {
        return $this->canViewDepartment($viewer, $division->department);
    }

    public function canViewStaff(User $viewer, User $staff): bool
    {
        if (in_array($viewer->role, [Role::Sysadmin, Role::Ps], true)) {
            return true;
        }

        return in_array($viewer->role, [Role::Commissioner, Role::Secretary], true)
            && $viewer->department_id !== null && $viewer->department_id === $staff->department_id;
    }
}
