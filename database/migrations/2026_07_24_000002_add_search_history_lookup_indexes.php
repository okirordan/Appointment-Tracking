<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('search_histories', function (Blueprint $table) {
            $table->index(['user_id', 'query'], 'search_histories_user_query_index');
            $table->index(['user_id', 'searched_at'], 'search_histories_user_recent_index');
        });
    }

    public function down(): void
    {
        Schema::table('search_histories', function (Blueprint $table) {
            $table->dropIndex('search_histories_user_query_index');
            $table->dropIndex('search_histories_user_recent_index');
        });
    }
};
