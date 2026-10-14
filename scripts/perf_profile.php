<?php

// Local, read-only route profiler. Run with: php scripts/perf_profile.php /home?mode=basic
require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
$path = $argv[1] ?? '/home?mode=basic';
$request = Illuminate\Http\Request::create($path, 'GET');
$app->instance('request', $request);

$user = App\Models\User::query()->where('role', App\Enums\Role::Ps->value)
    ->where('active', true)->orderBy('id')->firstOrFail();
Illuminate\Support\Facades\Auth::guard('web')->setUser($user);

// An in-memory cache gives every invocation the same cold-cache conditions
// and avoids modifying the application's persistent cache during profiling.
config(['cache.default' => 'array']);
Illuminate\Support\Facades\DB::beginTransaction();

$queries = [];
Illuminate\Support\Facades\DB::listen(function ($query) use (&$queries) {
    $fingerprint = preg_replace('/\s+/', ' ', $query->sql);
    $queries[] = ['ms' => round($query->time, 1), 'sql' => $fingerprint];
});

$start = hrtime(true);
$response = $kernel->handle($request);
$response->getContent();
$elapsed = round((hrtime(true) - $start) / 1_000_000, 1);
Illuminate\Support\Facades\DB::rollBack();

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
    'status' => $response->getStatusCode(),
    'elapsed_ms' => $elapsed,
    'query_count' => count($queries),
    'sql_ms' => round(array_sum(array_column($queries, 'ms')), 1),
    'slowest' => array_slice($groups, 0, 15),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
