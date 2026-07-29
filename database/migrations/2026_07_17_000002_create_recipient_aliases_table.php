<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipient_aliases', function (Blueprint $table) {
            $table->id();
            $table->string('alias', 100);
            $table->string('normalized_alias', 100);
            $table->string('target_type', 120);
            $table->unsignedBigInteger('target_id');
            $table->boolean('active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['normalized_alias', 'target_type', 'target_id'], 'recipient_alias_target_unique');
            $table->index(['active', 'normalized_alias'], 'recipient_alias_lookup_index');
            $table->index(['target_type', 'target_id'], 'recipient_alias_target_index');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->index(['active', 'locked', 'department_id', 'deleted_at'], 'users_recipient_availability_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_recipient_availability_index');
        });

        Schema::dropIfExists('recipient_aliases');
    }
};
