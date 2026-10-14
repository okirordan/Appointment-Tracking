<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEXES = [
        'mail_records' => [
            'mail_direction_unit_correspondence_idx' => ['direction', 'organizational_unit_id', 'correspondence_id'],
        ],
        'correspondence_recipients' => [
            'recipient_case_target_idx' => ['correspondence_id', 'active', 'target_type', 'user_id', 'organizational_unit_id'],
        ],
        'correspondence_forwards' => [
            'forward_case_sender_idx' => ['correspondence_id', 'status', 'forwarded_by_user_id'],
            'forward_case_unit_idx' => ['correspondence_id', 'status', 'from_organizational_unit_id'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $tableName => $indexes) {
            foreach ($indexes as $indexName => $columns) {
                if (! Schema::hasIndex($tableName, $indexName)) {
                    Schema::table($tableName, fn (Blueprint $table) => $table->index($columns, $indexName));
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $tableName => $indexes) {
            foreach ($indexes as $indexName => $columns) {
                if (Schema::hasIndex($tableName, $indexName)) {
                    Schema::table($tableName, fn (Blueprint $table) => $table->dropIndex($indexName));
                }
            }
        }
    }
};
