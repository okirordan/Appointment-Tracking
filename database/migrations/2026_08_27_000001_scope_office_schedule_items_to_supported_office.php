<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('office_schedule_items', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('secretary_office_attachment_id')->constrained('departments')->nullOnDelete();
            $table->foreignId('organizational_unit_id')->nullable()->after('department_id')->constrained('organizational_units')->nullOnDelete();
            $table->foreignId('office_supervisor_user_id')->nullable()->after('organizational_unit_id')->constrained('users')->nullOnDelete();
        });

        DB::table('office_schedule_items')
            ->join('secretary_office_attachments', 'secretary_office_attachments.id', '=', 'office_schedule_items.secretary_office_attachment_id')
            ->leftJoin('organizational_units', 'organizational_units.id', '=', 'secretary_office_attachments.organizational_unit_id')
            ->leftJoin('users as supervisors', 'supervisors.id', '=', 'secretary_office_attachments.supervisor_user_id')
            ->select([
                'office_schedule_items.id',
                'secretary_office_attachments.organizational_unit_id',
                'secretary_office_attachments.supervisor_user_id',
                'organizational_units.department_id as unit_department_id',
                'supervisors.department_id as supervisor_department_id',
            ])
            ->orderBy('office_schedule_items.id')
            ->each(function ($item): void {
                DB::table('office_schedule_items')->where('id', $item->id)->update([
                    'department_id' => $item->unit_department_id ?? $item->supervisor_department_id,
                    'organizational_unit_id' => $item->organizational_unit_id,
                    'office_supervisor_user_id' => $item->supervisor_user_id,
                ]);
            });

        Schema::table('office_schedule_items', function (Blueprint $table) {
            $table->foreignId('secretary_office_attachment_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('office_schedule_items')->whereNull('secretary_office_attachment_id')->delete();

        Schema::table('office_schedule_items', function (Blueprint $table) {
            $table->foreignId('secretary_office_attachment_id')->nullable(false)->change();
            $table->dropConstrainedForeignId('office_supervisor_user_id');
            $table->dropConstrainedForeignId('organizational_unit_id');
            $table->dropConstrainedForeignId('department_id');
        });
    }
};
