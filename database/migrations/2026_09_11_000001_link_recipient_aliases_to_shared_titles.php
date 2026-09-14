<?php

use App\Models\RecipientAlias;
use App\Services\Mail\SharedTitleDirectory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('annotation_titles', fn (Blueprint $table) => $table->boolean('disabled_by_admin')->default(false));
        Schema::table('recipient_aliases', function (Blueprint $table): void {
            $table->foreignId('annotation_title_id')->nullable()->constrained('annotation_titles')->nullOnDelete();
        });

        RecipientAlias::query()->with('target')->orderBy('id')->chunkById(100, function ($aliases): void {
            foreach ($aliases as $alias) {
                app(SharedTitleDirectory::class)->link($alias);
            }
        });
    }

    public function down(): void
    {
        // Titles can already be referenced by mail; retain them on rollback.
        Schema::table('recipient_aliases', fn (Blueprint $table) => $table->dropConstrainedForeignId('annotation_title_id'));
        Schema::table('annotation_titles', fn (Blueprint $table) => $table->dropColumn('disabled_by_admin'));
    }
};
