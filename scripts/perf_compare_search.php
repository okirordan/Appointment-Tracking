<?php

use App\Enums\Role;
use App\Models\CorrespondenceUpdate;
use App\Models\MailRecord;
use App\Models\User;
use App\Services\Mail\MailAccessScope;
use App\Services\MailIndexedSearch;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$user = User::query()->where('role', Role::Ps->value)
    ->where('active', true)->orderBy('id')->firstOrFail();
$terms = array_slice($argv, 1) ?: ['education', 'Kabale', 'reconstruction', 'PS/ES'];
$scope = app(MailAccessScope::class);
$matcher = app(MailIndexedSearch::class);
$comparisons = [];

foreach ($terms as $term) {
    $old = $scope->apply(MailRecord::query()->matchingKeywords($term), $user);
    $new = $scope->apply($matcher->apply(MailRecord::query(), $term), $user);

    $start = hrtime(true);
    $oldIds = $old->pluck('mail_records.id')->map(fn ($id) => (int) $id)->all();
    $oldMs = round((hrtime(true) - $start) / 1_000_000, 1);
    $start = hrtime(true);
    $newIds = $new->pluck('mail_records.id')->map(fn ($id) => (int) $id)->all();
    $newMs = round((hrtime(true) - $start) / 1_000_000, 1);

    $comparisons[] = [
        'term' => $term,
        'old_count' => count($oldIds),
        'new_count' => count($newIds),
        'old_ms' => $oldMs,
        'new_ms' => $newMs,
        'old_only_count' => count(array_diff($oldIds, $newIds)),
        'new_only_count' => count(array_diff($newIds, $oldIds)),
        'old_only_sample_ids' => array_slice(array_values(array_diff($oldIds, $newIds)), 0, 10),
        'new_only_sample_ids' => array_slice(array_values(array_diff($newIds, $oldIds)), 0, 10),
    ];
}

echo json_encode([
    'unindexed_mail_records' => MailRecord::query()->whereNull('search_text')->count(),
    'correspondence_updates' => CorrespondenceUpdate::query()->count(),
    'comparisons' => $comparisons,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
