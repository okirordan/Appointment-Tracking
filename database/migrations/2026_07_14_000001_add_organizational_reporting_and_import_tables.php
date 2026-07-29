<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->string('external_id')->nullable()->index();
        });

        Schema::create('divisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('code', 30);
            $table->string('external_id')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['department_id', 'name']);
            $table->unique(['department_id', 'code']);
            $table->index(['department_id', 'active']);
            $table->index('external_id');
        });

        Schema::create('workstreams', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->index();
            $table->string('name');
            $table->string('code', 40)->nullable();
            $table->text('description')->nullable();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_id')->nullable()->index();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['type', 'name']);
            $table->index(['department_id', 'active']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('division_id')->nullable()->after('department_id')->constrained()->nullOnDelete();
            $table->string('employee_number', 80)->nullable()->after('division_id')->index();
            $table->string('external_id')->nullable()->after('employee_number')->index();
            $table->index(['department_id', 'division_id', 'active']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('division_id')->nullable()->after('department_id')->constrained()->nullOnDelete();
            $table->foreignId('workstream_id')->nullable()->after('division_id')->constrained()->nullOnDelete();
            $table->string('external_id')->nullable()->after('workstream_id')->index();
            $table->index(['department_id', 'division_id', 'created_at']);
            $table->index(['workstream_id', 'created_at']);
            $table->index(['department_id', 'workflow_status', 'created_at']);
            $table->index(['workflow_status', 'due_date']);
        });

        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('initiated_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('source_system', 100);
            $table->string('entity_type', 30);
            $table->string('status', 30)->index();
            $table->string('original_filename');
            $table->string('storage_key');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->string('checksum', 64)->index();
            $table->json('mapping_json')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('created_rows')->default(0);
            $table->unsignedInteger('updated_rows')->default(0);
            $table->unsignedInteger('skipped_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['source_system', 'checksum']);
        });

        Schema::create('import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('status', 30)->index();
            $table->json('normalized_json');
            $table->json('issues_json')->nullable();
            $table->string('matched_type', 60)->nullable();
            $table->unsignedBigInteger('matched_id')->nullable();
            $table->timestamps();
            $table->unique(['import_batch_id', 'row_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('import_batches');
        Schema::table('tasks', fn (Blueprint $table) => $table->dropConstrainedForeignId('workstream_id'));
        Schema::table('tasks', fn (Blueprint $table) => $table->dropConstrainedForeignId('division_id'));
        Schema::table('tasks', fn (Blueprint $table) => $table->dropColumn('external_id'));
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('division_id'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['employee_number', 'external_id']));
        Schema::dropIfExists('workstreams');
        Schema::dropIfExists('divisions');
        Schema::table('departments', fn (Blueprint $table) => $table->dropColumn('external_id'));
    }
};
