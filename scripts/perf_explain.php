<?php

use App\Enums\Role;
use App\Models\MailRecord;
use App\Models\User;
use App\Services\Mail\MailAccessScope;
use App\Services\Mail\MailboxScope;
use App\Services\MailIndexedSearch;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$db = DB::connection();
$user = User::query()->where('role', Role::Ps->value)
    ->where('active', true)->orderBy('id')->firstOrFail();
$term = $argv[1] ?? 'education';

$search = MailRecord::query()->matchingKeywords($term);
app(MailAccessScope::class)->apply($search, $user);
$search->select('mail_records.id')->orderBySearchRelevance($term)->orderByDesc('created_at')->limit(20);

$indexedSearch = app(MailIndexedSearch::class)->apply(MailRecord::query(), $term);
app(MailAccessScope::class)->apply($indexedSearch, $user);
$indexedSearch->select('mail_records.id')->orderByDesc('created_at')->orderByDesc('id')->limit(20);

$incoming = app(MailboxScope::class)
    ->incoming(MailRecord::query(), $user);
app(MailAccessScope::class)->apply($incoming, $user);
$incoming->select('mail_records.id')->orderByDesc('cursor_received_date')->orderByDesc('mail_records.id')->limit(25);

$outgoing = app(MailboxScope::class)
    ->outgoing(MailRecord::query(), $user);
app(MailAccessScope::class)->apply($outgoing, $user);
$outgoing->select('mail_records.id')->orderByDesc('mail_records.id')->limit(25);

$indexes = [];
foreach (['mail_records', 'correspondences', 'correspondence_recipients', 'correspondence_forwards', 'correspondence_updates'] as $table) {
    $indexes[$table] = array_map(
        fn ($row) => [
            'name' => $row->Key_name,
            'column' => $row->Column_name,
            'sequence' => $row->Seq_in_index,
            'cardinality' => $row->Cardinality,
        ],
        $db->select("SHOW INDEX FROM `{$table}`"),
    );
}

$explain = fn ($query) => array_map(fn ($row) => (array) $row,
    $db->select('EXPLAIN '.$query->toSql(), $query->getBindings()));

echo json_encode([
    'innodb_ft_min_token_size' => $db->select("SHOW VARIABLES LIKE 'innodb_ft_min_token_size'")[0]->Value,
    'indexes' => $indexes,
    'explain' => [
        'search' => $explain($search),
        'indexed_search' => $explain($indexedSearch),
        'incoming' => $explain($incoming),
        'outgoing' => $explain($outgoing),
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
