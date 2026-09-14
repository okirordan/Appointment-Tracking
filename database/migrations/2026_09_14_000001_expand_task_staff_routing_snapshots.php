<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_histories', function (Blueprint $table) {
            $table->text('annotation_origin_snapshot')->nullable()->change();
            $table->text('annotation_recipient_snapshot')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Keep the wider columns so rolling back cannot truncate recorded staff names.
    }
};
