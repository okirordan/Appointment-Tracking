<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff IDs are a login identifier (AUTH-010), so duplicates must be
 * impossible at the database level. NULL stays allowed for accounts
 * that have not been issued a staff ID.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['employee_number']);
            $table->unique('employee_number');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['employee_number']);
            $table->index('employee_number');
        });
    }
};
