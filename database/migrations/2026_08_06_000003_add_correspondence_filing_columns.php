<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('correspondences', function (Blueprint $table) {
            $table->timestamp('filed_at')->nullable()->after('closed_at');
            $table->foreignId('filed_by_user_id')->nullable()->after('filed_at')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('filed_organizational_unit_id')->nullable()->after('filed_by_user_id')
                ->constrained('organizational_units')->nullOnDelete();
            $table->foreignId('filed_department_id')->nullable()->after('filed_organizational_unit_id')
                ->constrained('departments')->nullOnDelete();
            $table->string('filing_category', 120)->nullable()->after('filed_department_id');
            $table->text('filing_note')->nullable()->after('filing_category');

            $table->index(['current_status', 'filed_at'], 'correspondence_filed_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('correspondences', function (Blueprint $table) {
            $table->dropIndex('correspondence_filed_status_idx');
            $table->dropConstrainedForeignId('filed_by_user_id');
            $table->dropConstrainedForeignId('filed_organizational_unit_id');
            $table->dropConstrainedForeignId('filed_department_id');
            $table->dropColumn(['filed_at', 'filing_category', 'filing_note']);
        });
    }
};
