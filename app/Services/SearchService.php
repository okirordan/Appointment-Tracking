<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Department;
use App\Models\Division;
use App\Models\MailRecord;
use App\Models\SearchHistory;
use App\Models\Task;
use App\Models\User;
use App\Models\Workstream;
use App\Services\Mail\MailRecordPresenter;
use App\Services\Tasks\TaskPresenter;
use App\Services\Tasks\TaskScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class SearchService
{
    public function __construct(
        private TaskScope $scope,
        private TaskPresenter $presenter,
        private MailRecordPresenter $mailPresenter,
        private Mail\MailAccessScope $mailAccess,
        private DepartmentAccessService $departments,
    ) {}

    /**
     * Global search (PRD §12.2): results always respect role and
     * department scope; officers only ever see their own tasks.
     *
     * @return array<string, mixed>
     */
    public function search(
        User $user,
        string $term,
        ?string $type = null,
        bool $includeSuggestion = true,
        int $page = 1,
        int $perPage = 20,
    ): array {
        $type ??= 'all';
        $term = preg_replace('/\s+/u', ' ', trim($term)) ?? trim($term);
        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));

        return Cache::flexible(
            SearchCache::resultKey($user, $term, $type, $includeSuggestion, $page, $perPage),
            [
                (int) config('ats.search.cache_fresh_seconds', 20),
                (int) config('ats.search.cache_stale_seconds', 90),
            ],
            fn () => $this->performSearch($user, $term, $type, $includeSuggestion, $page, $perPage),
        );
    }

    /**
     * Execute an uncached, permission-scoped search.
     *
     * @return array<string, mixed>
     */
    private function performSearch(
        User $user,
        string $term,
        string $type,
        bool $includeSuggestion,
        int $page,
        int $perPage,
    ): array {
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
        $isPaginated = $type !== 'all';
        $offset = $isPaginated ? ($page - 1) * $perPage : 0;
        $limit = $isPaginated ? $perPage : 5;

        $visibleTasks = $this->scope->query($user);
        $taskQuery = (clone $visibleTasks)
            ->with(['department', 'division', 'workstream'])
            ->matchingKeywords($term, ['reference', 'description', 'assigned_to_name_snapshot']);
        $taskCountQuery = clone $taskQuery;
        $this->orderByRelevance($taskQuery, ['title', 'reference', 'assigned_to_name_snapshot'], $term)
            ->orderByDesc('updated_at');

        $counts = [
            'mails' => 0,
            'tasks' => 0,
            'officers' => 0,
            'departments' => 0,
            'divisions' => 0,
            'workstreams' => 0,
        ];
        $tasks = [];
        if ($this->includes($type, 'tasks')) {
            $rows = $taskQuery->offset($offset)->limit($limit)->get();
            $counts['tasks'] = $this->totalFor($rows->count(), $offset, $limit, fn () => $taskCountQuery->count());
            $tasks = $rows
                ->map(fn (Task $task) => [
                    ...$this->presenter->row($task),
                    'description' => $task->description,
                    'division_name' => $task->division?->name,
                    'workstream_name' => $task->workstream?->name,
                ])
                ->all();
        }

        $officers = [];
        $departments = [];
        $divisions = [];
        $workstreams = [];
        $mails = [];

        if ($user->role !== Role::Officer) {
            $officerQuery = User::query()
                ->where('active', true)
                ->where(fn ($query) => $query
                    ->where('full_name', 'like', $like)
                    ->orWhere('title', 'like', $like));

            // Commissioners and Secretaries only see their own department's staff.
            if (in_array($user->role, [Role::Commissioner, Role::Secretary], true)) {
                $officerQuery->where('department_id', $user->department_id);
            }

            if ($this->includes($type, 'staff')) {
                $officerCountQuery = clone $officerQuery;
                $this->orderByRelevance($officerQuery, ['full_name', 'title'], $term)->orderBy('full_name');
                $rows = $officerQuery->offset($offset)->limit($limit)->get();
                $counts['officers'] = $this->totalFor($rows->count(), $offset, $limit, fn () => $officerCountQuery->count());
                $officers = $rows
                    ->map(fn (User $officer) => [
                        'id' => $officer->id,
                        'full_name' => $officer->full_name,
                        'title' => $officer->title,
                        'initials' => $officer->initials(),
                    ])->all();
            }

            $departmentQuery = Department::query()
                ->withCount([
                    'users as active_officer_count' => fn (Builder $query) => $query
                        ->where('active', true)
                        ->where('role', Role::Officer->value),
                ])
                ->where('active', true)
                ->where(fn (Builder $query) => $query
                    ->where('name', 'like', $like)
                    ->orWhere('code', 'like', $like))
                ->when(in_array($user->role, [Role::Commissioner, Role::Secretary], true), fn ($query) => $query->whereKey($user->department_id));

            if ($this->includes($type, 'departments')) {
                $departmentCountQuery = clone $departmentQuery;
                $this->orderByRelevance($departmentQuery, ['name', 'code'], $term)->orderBy('name');
                $rows = $departmentQuery->offset($offset)->limit($limit)->get();
                $counts['departments'] = $this->totalFor($rows->count(), $offset, $limit, fn () => $departmentCountQuery->count());
                $departments = $rows
                    ->map(fn (Department $department) => [
                        'id' => $department->id,
                        'name' => $department->name,
                        'code' => $department->code,
                        'officer_count' => (int) $department->active_officer_count,
                    ])->all();
            }

            $divisionQuery = Division::query()->with('department:id,name')->where('active', true)
                ->where(fn ($query) => $query->where('name', 'like', $like)->orWhere('code', 'like', $like))
                ->when($user->role === Role::Clerk, fn ($query) => $query->whereRaw('1 = 0'))
                ->when(in_array($user->role, [Role::Commissioner, Role::Secretary], true), fn ($query) => $query->where('department_id', $user->department_id));

            if ($this->includes($type, 'divisions')) {
                $divisionCountQuery = clone $divisionQuery;
                $this->orderByRelevance($divisionQuery, ['name', 'code'], $term)->orderBy('name');
                $rows = $divisionQuery->offset($offset)->limit($limit)->get();
                $counts['divisions'] = $this->totalFor($rows->count(), $offset, $limit, fn () => $divisionCountQuery->count());
                $divisions = $rows
                    ->map(fn (Division $division) => [
                        'id' => $division->id, 'name' => $division->name, 'code' => $division->code,
                        'department_id' => $division->department_id, 'department_name' => $division->department->name,
                    ])->all();
            }
        }

        $workstreamIds = (clone $visibleTasks)->whereNotNull('workstream_id')->select('workstream_id');
        $workstreamQuery = Workstream::query()->where('active', true)->whereIn('id', $workstreamIds)
            ->where(fn ($query) => $query->where('name', 'like', $like)->orWhere('code', 'like', $like)->orWhere('description', 'like', $like));
        if ($this->includes($type, 'workstreams')) {
            $workstreamCountQuery = clone $workstreamQuery;
            $this->orderByRelevance($workstreamQuery, ['name', 'code'], $term)->orderBy('name');
            $rows = $workstreamQuery->offset($offset)->limit($limit)->get();
            $counts['workstreams'] = $this->totalFor($rows->count(), $offset, $limit, fn () => $workstreamCountQuery->count());
            $workstreams = $rows
                ->map(fn (Workstream $item) => [
                    'id' => $item->id, 'name' => $item->name, 'code' => $item->code, 'type' => $item->type,
                ])->all();
        }

        if ($this->includes($type, 'mail')) {
            $mailQuery = MailRecord::query()
                ->with(['task.department', 'routingTask.department', 'department', 'organizationalUnit'])
                ->matchingKeywords($term);
            $this->mailAccess->apply($mailQuery, $user);
            $mailCountQuery = clone $mailQuery;
            $mailQuery->orderBySearchRelevance($term)->orderByDesc('created_at');
            $rows = $mailQuery->offset($offset)->limit($limit)->get();
            $counts['mails'] = $this->totalFor($rows->count(), $offset, $limit, fn () => $mailCountQuery->count());
            $mails = $rows
                ->map(fn (MailRecord $mail) => [
                    ...$this->mailPresenter->row($mail),
                    'sender_organisation' => $mail->sender_organisation,
                    'details' => $mail->details,
                ])
                ->all();
        }

        $total = array_sum($counts);
        $results = [
            'mails' => $mails,
            'tasks' => $tasks,
            'officers' => $officers,
            'departments' => $departments,
            'divisions' => $divisions,
            'workstreams' => $workstreams,
            'counts' => $counts,
            'total' => $total,
            'per_page' => $perPage,
            'pagination' => $isPaginated ? [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'total' => $total,
            ] : null,
            'did_you_mean' => null,
        ];

        if ($includeSuggestion && $results['total'] === 0) {
            $suggestedTerm = $this->suggestedTerm($user, $visibleTasks, $term);

            if ($suggestedTerm !== null) {
                $suggestedResults = $this->search($user, $suggestedTerm, $type, false, 1, $perPage);

                if ($suggestedResults['total'] > 0) {
                    $results['did_you_mean'] = [
                        'term' => $suggestedTerm,
                        'results' => $suggestedResults,
                    ];
                }
            }
        }

        return $results;
    }

    private function includes(string $requestedType, string $candidate): bool
    {
        return $requestedType === 'all' || $requestedType === $candidate;
    }

    /**
     * Resolve a category total without a COUNT(*) query when the fetched
     * page was not full — the total is then exactly offset + rows. A full
     * page (or an empty page beyond the first) still needs the real count.
     */
    private function totalFor(int $fetched, int $offset, int $limit, callable $exactCount): int
    {
        if ($fetched < $limit && ($fetched > 0 || $offset === 0)) {
            return $offset + $fetched;
        }

        return (int) $exactCount();
    }

    /**
     * Put exact and prefix matches ahead of looser contains matches while
     * retaining the category's normal date/name ordering as a tie-breaker.
     *
     * @param  list<string>  $columns
     */
    private function orderByRelevance(Builder $query, array $columns, string $term): Builder
    {
        $normalised = mb_strtolower(trim($term));
        $exact = [];
        $prefix = [];
        $bindings = [];

        foreach ($columns as $column) {
            $exact[] = "LOWER(COALESCE({$column}, '')) = ?";
            $bindings[] = $normalised;
        }
        foreach ($columns as $column) {
            $prefix[] = "LOWER(COALESCE({$column}, '')) LIKE ?";
            $bindings[] = $normalised.'%';
        }

        return $query->orderByRaw(
            'CASE WHEN '.implode(' OR ', $exact).' THEN 0 WHEN '.implode(' OR ', $prefix).' THEN 1 ELSE 2 END',
            $bindings,
        );
    }

    /**
     * Build spelling suggestions only from task titles and subjects the
     * current viewer is already authorised to see. This prevents suggestions
     * from leaking words from restricted assignments.
     */
    private function suggestedTerm(User $user, Builder $visibleTasks, string $term): ?string
    {
        // Building the dictionary hydrates up to 1500 tasks — far too heavy
        // to repeat on every zero-result keystroke, so cache it briefly per
        // user (the word list is scoped to what that user may see).
        $dictionary = Cache::remember(
            'ats:search-dictionary:'.SearchCache::version().":{$user->id}",
            now()->addMinutes(10),
            function () use ($visibleTasks, $user) {
                $phrases = (clone $visibleTasks)
                    ->with('workstream:id,name')
                    ->limit(1200)
                    ->get(['title', 'workstream_id'])
                    ->flatMap(fn (Task $task) => [$task->title, $task->workstream?->name]);

                $mails = MailRecord::query();
                $this->mailAccess->apply($mails, $user);
                $phrases = $phrases->concat(
                    $mails->limit(1200)
                        ->get(['sender_name', 'sender_organisation', 'recipient_name', 'subject'])
                        ->flatMap(fn (MailRecord $mail) => [
                            $mail->sender_name,
                            $mail->sender_organisation,
                            $mail->recipient_name,
                            $mail->subject,
                        ]),
                );

                return $phrases->filter()
                    ->flatMap(fn (string $phrase) => preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(Str::ascii($phrase))) ?: [])
                    ->filter(fn (string $word) => mb_strlen($word) >= 3)
                    ->unique()
                    ->values()
                    ->all();
            },
        );

        if ($dictionary === []) {
            return null;
        }

        $words = array_values(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(Str::ascii(trim($term)))) ?: [],
            fn (string $word) => $word !== '',
        ));

        $changed = false;
        $corrected = array_map(function (string $word) use ($dictionary, &$changed) {
            if (in_array($word, $dictionary, true) || mb_strlen($word) < 3) {
                return $word;
            }

            $closest = $this->closestWord($word, $dictionary);
            if ($closest === null) {
                return $word;
            }

            $changed = true;

            return $closest;
        }, $words);

        if (! $changed) {
            return null;
        }

        return implode(' ', $corrected);
    }

    /** @param list<string> $dictionary */
    private function closestWord(string $word, array $dictionary): ?string
    {
        $best = null;
        $bestScore = PHP_FLOAT_MAX;

        foreach ($dictionary as $candidate) {
            $longest = max(strlen($word), strlen($candidate));
            $maximumDistance = match (true) {
                $longest <= 4 => 1,
                $longest <= 8 => 2,
                default => 3,
            };

            if (abs(strlen($word) - strlen($candidate)) > $maximumDistance) {
                continue;
            }

            $distance = levenshtein($word, $candidate);
            $score = $longest === 0 ? 1 : $distance / $longest;

            if ($distance <= $maximumDistance && $score <= 0.3 && $score < $bestScore) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * SRCH-008/009: retain the latest ten searches, private per user.
     */
    public function remember(User $user, string $term): void
    {
        SearchHistory::where('user_id', $user->id)->where('query', $term)->delete();

        SearchHistory::create([
            'user_id' => $user->id,
            'query' => $term,
            'searched_at' => now(),
        ]);

        $stale = SearchHistory::where('user_id', $user->id)
            ->orderByDesc('searched_at')
            ->skip(config('ats.search.recent_per_user'))
            ->take(50)
            ->pluck('id');

        SearchHistory::whereIn('id', $stale)->delete();
    }

    /** @return list<string> */
    public function recent(User $user): array
    {
        return SearchHistory::where('user_id', $user->id)
            ->orderByDesc('searched_at')
            ->limit(config('ats.search.recent_per_user'))
            ->pluck('query')
            ->all();
    }
}
