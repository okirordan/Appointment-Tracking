<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, string> */
    private array $ministerialOffices = [
        'OMES' => 'Office of the Minister of Education & Sports',
        'OSMS' => 'Office of the State Minister for Sports',
        'OSMPE' => 'Office of the State Minister for Primary Education',
        'OSMHE' => 'Office of the State Minister for Higher Education',
    ];

    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('owner_organizational_unit_id')
                ->nullable()
                ->after('owner_user_id')
                ->constrained('organizational_units')
                ->nullOnDelete();
            $table->index(['owner_organizational_unit_id', 'workflow_status'], 'task_owner_unit_status_idx');
        });

        Schema::table('correspondence_recipients', function (Blueprint $table) {
            $table->string('routing_status', 20)->default('received')->after('active')->index();
            $table->timestamp('received_at')->nullable()->after('added_at')->index();
            $table->foreignId('received_by_user_id')->nullable()->after('received_at')->constrained('users')->nullOnDelete();
        });

        $now = now();
        foreach ($this->ministerialOffices as $code => $name) {
            DB::table('organizational_units')->updateOrInsert(
                ['code' => $code],
                [
                    'parent_id' => null,
                    'department_id' => null,
                    'division_id' => null,
                    'type' => 'ministerial_office',
                    'name' => $name,
                    'active' => true,
                    'deleted_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        DB::table('correspondence_recipients')
            ->whereIn('target_type', ['individual', 'multiple', 'office', 'department'])
            ->whereNull('received_at')
            ->update(['routing_status' => 'received', 'received_at' => DB::raw('added_at')]);
        DB::table('correspondence_recipients')
            ->where('target_type', 'external')
            ->update(['routing_status' => 'sent', 'received_at' => null]);

        DB::table('tasks')
            ->whereNull('owner_organizational_unit_id')
            ->orderBy('id')
            ->chunkById(500, function ($tasks): void {
                foreach ($tasks as $task) {
                    $mailUnitId = DB::table('mail_records')
                        ->where('task_id', $task->id)
                        ->whereNotNull('organizational_unit_id')
                        ->value('organizational_unit_id');
                    $ownerUnitId = $mailUnitId ?? DB::table('users')
                        ->where('id', $task->creator_user_id ?? $task->assigned_by_user_id)
                        ->value('organizational_unit_id');

                    if ($ownerUnitId !== null) {
                        DB::table('tasks')->where('id', $task->id)->update([
                            'owner_organizational_unit_id' => $ownerUnitId,
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('correspondence_recipients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('received_by_user_id');
            $table->dropColumn(['routing_status', 'received_at']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex('task_owner_unit_status_idx');
            $table->dropConstrainedForeignId('owner_organizational_unit_id');
        });

        foreach (array_keys($this->ministerialOffices) as $code) {
            DB::table('organizational_units')
                ->where('code', $code)
                ->whereNotExists(fn ($query) => $query
                    ->selectRaw('1')
                    ->from('users')
                    ->whereColumn('users.organizational_unit_id', 'organizational_units.id'))
                ->whereNotExists(fn ($query) => $query
                    ->selectRaw('1')
                    ->from('mail_records')
                    ->whereColumn('mail_records.organizational_unit_id', 'organizational_units.id'))
                ->whereNotExists(fn ($query) => $query
                    ->selectRaw('1')
                    ->from('correspondences')
                    ->whereColumn('correspondences.organizational_unit_id', 'organizational_units.id'))
                ->whereNotExists(fn ($query) => $query
                    ->selectRaw('1')
                    ->from('secretary_office_attachments')
                    ->whereColumn('secretary_office_attachments.organizational_unit_id', 'organizational_units.id'))
                ->whereNotExists(fn ($query) => $query
                    ->selectRaw('1')
                    ->from('correspondence_recipients')
                    ->whereColumn('correspondence_recipients.organizational_unit_id', 'organizational_units.id'))
                ->whereNotExists(fn ($query) => $query
                    ->selectRaw('1')
                    ->from('correspondence_forwards')
                    ->whereColumn('correspondence_forwards.from_organizational_unit_id', 'organizational_units.id'))
                ->whereNotExists(fn ($query) => $query
                    ->selectRaw('1')
                    ->from('positions')
                    ->whereColumn('positions.organizational_unit_id', 'organizational_units.id'))
                ->delete();
        }
    }
};
