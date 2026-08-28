<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('organizational_unit_id')
                ->nullable()
                ->after('division_id')
                ->constrained('organizational_units')
                ->nullOnDelete();
            $table->index(['organizational_unit_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['organizational_unit_id', 'active']);
            $table->dropConstrainedForeignId('organizational_unit_id');
        });
    }
};
