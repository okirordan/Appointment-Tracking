<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('correspondence_office_aliases', function (Blueprint $table) {
            $table->string('kind', 24)->default('office');
        });
        Schema::table('correspondence_updates', function (Blueprint $table) {
            $table->foreignId('destination_office_alias_id')->nullable()
                ->constrained('correspondence_office_aliases', 'id', 'basic_destination_alias_fk')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('correspondence_updates', function (Blueprint $table) {
            $table->dropForeign('basic_destination_alias_fk');
            $table->dropColumn('destination_office_alias_id');
        });
        Schema::table('correspondence_office_aliases', fn (Blueprint $table) => $table->dropColumn('kind'));
    }
};
