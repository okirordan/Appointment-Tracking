<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('mail_records', 'office_supervisor_user_id')) {
            Schema::table('mail_records', function (Blueprint $table) {
                $table->foreignId('office_supervisor_user_id')->nullable()->after('registry_file_number')->constrained('users')->nullOnDelete();
                $table->foreignId('organizational_unit_id')->nullable()->after('office_supervisor_user_id')->constrained('organizational_units')->nullOnDelete();
                $table->foreignId('prepared_on_behalf_of_user_id')->nullable()->after('organizational_unit_id')->constrained('users')->nullOnDelete();
                $table->foreignId('last_processed_by_user_id')->nullable()->after('prepared_on_behalf_of_user_id')->constrained('users')->nullOnDelete();
                $table->string('status', 40)->default('registered')->after('last_processed_by_user_id')->index();
                $table->string('priority', 20)->default('medium')->after('status')->index();
                $table->string('financial_year', 9)->nullable()->after('priority')->index();
                $table->string('dispatch_method', 50)->nullable()->after('financial_year');
                $table->string('dispatch_reference')->nullable()->after('dispatch_method');
                $table->timestamp('dispatched_at')->nullable()->after('dispatch_reference')->index();
                $table->foreignId('reviewed_by_user_id')->nullable()->after('dispatched_at')->constrained('users')->nullOnDelete();
                $table->timestamp('reviewed_at')->nullable()->after('reviewed_by_user_id');
                $table->text('review_notes')->nullable()->after('reviewed_at');
                $table->foreignId('approved_by_user_id')->nullable()->after('review_notes')->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable()->after('approved_by_user_id');
                $table->timestamp('archived_at')->nullable()->after('approved_at');
                $table->index(['office_supervisor_user_id', 'direction', 'status'], 'mail_office_direction_status_idx');
            });
        }

        $psId = DB::table('users')->where('role', 'ps')->where('active', true)->orderBy('id')->value('id');
        $officeId = DB::table('organizational_units')->where('name', 'Office of the Permanent Secretary')->value('id');
        DB::table('mail_records')->whereNull('office_supervisor_user_id')->update(['office_supervisor_user_id' => $psId]);
        DB::table('mail_records')->whereNull('organizational_unit_id')->update(['organizational_unit_id' => $officeId]);
        DB::table('mail_records')->whereNull('last_processed_by_user_id')->update(['last_processed_by_user_id' => DB::raw('captured_by_user_id')]);
        DB::table('mail_records')->where('direction', 'incoming')->whereNull('task_id')->where('status', '!=', 'registered')->update(['status' => 'registered']);
        DB::table('mail_records')->where('direction', 'incoming')->whereNotNull('task_id')->where('status', '!=', 'assigned')->update(['status' => 'assigned']);
        DB::table('mail_records')->where('direction', 'outgoing')->whereNull('sent_date')->where('status', '!=', 'draft')->update(['status' => 'draft']);
        DB::table('mail_records')
            ->where('direction', 'outgoing')
            ->whereNotNull('sent_date')
            ->where(fn ($query) => $query->where('status', '!=', 'dispatched')->orWhereNull('dispatched_at'))
            ->update([
                'status' => 'dispatched',
                'dispatched_at' => DB::raw('sent_date'),
            ]);

        $date = 'COALESCE(received_date, sent_date, letter_date, created_at)';
        $financialYearExpression = match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => "CONCAT(YEAR(DATE_SUB({$date}, INTERVAL 6 MONTH)), '/', LPAD(MOD(YEAR(DATE_SUB({$date}, INTERVAL 6 MONTH)) + 1, 100), 2, '0'))",
            'pgsql' => "TO_CHAR(({$date})::timestamp - INTERVAL '6 months', 'YYYY') || '/' || TO_CHAR(({$date})::timestamp + INTERVAL '6 months', 'YY')",
            default => "strftime('%Y', {$date}, '-6 months') || '/' || substr(CAST(CAST(strftime('%Y', {$date}, '-6 months') AS INTEGER) + 1 AS TEXT), 3, 2)",
        };
        DB::table('mail_records')
            ->whereNull('financial_year')
            ->update(['financial_year' => DB::raw($financialYearExpression)]);

        $activePsSecretaries = DB::table('secretary_office_attachments')
            ->where('supervisor_user_id', $psId)
            ->where('active', true)
            ->pluck('secretary_user_id');
        if ($activePsSecretaries->isNotEmpty()) {
            DB::table('mail_records')
                ->where('direction', 'outgoing')
                ->whereIn('captured_by_user_id', $activePsSecretaries)
                ->whereNull('prepared_on_behalf_of_user_id')
                ->update(['prepared_on_behalf_of_user_id' => $psId]);
        }
    }

    public function down(): void
    {
        Schema::table('mail_records', function (Blueprint $table) {
            $table->dropIndex('mail_office_direction_status_idx');
            $table->dropConstrainedForeignId('approved_by_user_id');
            $table->dropConstrainedForeignId('reviewed_by_user_id');
            $table->dropConstrainedForeignId('last_processed_by_user_id');
            $table->dropConstrainedForeignId('prepared_on_behalf_of_user_id');
            $table->dropConstrainedForeignId('organizational_unit_id');
            $table->dropConstrainedForeignId('office_supervisor_user_id');
            $table->dropColumn([
                'status', 'priority', 'financial_year', 'dispatch_method', 'dispatch_reference',
                'dispatched_at', 'reviewed_at', 'review_notes', 'approved_at', 'archived_at',
            ]);
        });
    }
};
