<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

// Local, read-only route profiler. Run with: php scripts/perf_profile.php /home?mode=basic
require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
$path = $argv[1] ?? '/home?mode=basic';
$request = Request::create($path, 'GET');
$app->instance('request', $request);

$role = Role::from($argv[2] ?? 'ps');
$user = User::query()->where('role', $role->value)
    ->where('active', true)->orderBy('id')->firstOrFail();
Auth::guard('web')->setUser($user);

// An in-memory cache gives every invocation the same cold-cache conditions
// and avoids modifying the application's persistent cache during profiling.
config(['cache.default' => 'array']);
DB::beginTransaction();

$queries = [];
DB::listen(function ($query) use (&$queries) {
    $fingerprint = preg_replace('/\s+/', ' ', $query->sql);
    $queries[] = ['ms' => round($query->time, 1), 'sql' => $fingerprint];
});

$start = hrtime(true);
$response = $kernel->handle($request);
$response->getContent();
$elapsed = round((hrtime(true) - $start) / 1_000_000, 1);
DB::rollBack();

$groups = [];
foreach ($queries as $query) {
    $sql = $query['sql'];
    $key = hash('sha256', $sql);
    $groups[$key] ??= ['count' => 0, 'total_ms' => 0, 'max_ms' => 0, 'sql' => mb_substr($sql, 0, 220)];
    $groups[$key]['count']++;
    $groups[$key]['total_ms'] += $query['ms'];
    $groups[$key]['max_ms'] = max($groups[$key]['max_ms'], $query['ms']);
}
usort($groups, fn ($a, $b) => $b['total_ms'] <=> $a['total_ms']);

echo json_encode([
    'path' => $path,
    'role' => $role->value,
    'status' => $response->getStatusCode(),
    'elapsed_ms' => $elapsed,
    'query_count' => count($queries),
    'sql_ms' => round(array_sum(array_column($queries, 'ms')), 1),
    'slowest' => array_slice($groups, 0, 15),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
