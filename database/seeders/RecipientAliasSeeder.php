<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Department;
use App\Models\Position;
use App\Models\RecipientAlias;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;

class RecipientAliasSeeder extends Seeder
{
    public function run(): void
    {
        $permanentSecretary = User::query()->where('role', Role::Ps->value)->where('active', true)->first();
        $hrmCommissioner = Position::query()->where('title', 'Commissioner – Human Resource Management')->where('active', true)->first();
        $hrmDepartment = Department::query()->where('code', 'HRM')->where('active', true)->first();

        $this->seedAlias('PS/ES', $permanentSecretary);
        $this->seedAlias('C/HRM', $hrmCommissioner);
        $this->seedAlias('HRM', $hrmDepartment);
    }

    private function seedAlias(string $value, ?Model $target): void
    {
        if ($target === null) {
            return;
        }

        $alias = RecipientAlias::withTrashed()->firstOrNew([
            'normalized_alias' => RecipientAlias::normalize($value),
            'target_type' => $target::class,
            'target_id' => $target->getKey(),
        ]);
        $alias->fill(['alias' => $value, 'active' => true]);
        $alias->deleted_at = null;
        $alias->save();
    }
}
