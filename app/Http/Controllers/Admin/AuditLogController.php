<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\LogRedactor;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $validated = $request->validate([
            'tab' => ['nullable', Rule::in(['users', 'activity', 'system', 'laravel', 'failed'])],
            'q' => ['nullable', 'string', 'max:200'], 'category' => ['nullable', 'string', 'max:80'],
            'action' => ['nullable', 'string', 'max:255'], 'actor' => ['nullable', 'integer'],
            'outcome' => ['nullable', Rule::in(['success', 'failure'])],
            'severity' => ['nullable', Rule::in(['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'])],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $filters = array_merge(array_fill_keys(['q', 'category', 'action', 'actor', 'outcome', 'severity', 'from', 'to'], ''), ['tab' => 'users'], array_filter($validated, fn ($value) => $value !== null));
        $base = AuditLog::query();
        if (! $request->user()->isSuperAdmin()) {
            $base->whereNotIn('category', ['mail']);
        }
        $query = clone $base;
        match ($filters['tab']) {
            'users' => $query->whereNotNull('actor_user_id')->whereNotIn('category', ['system', 'laravel', 'failed_action']),
            'activity' => $query->whereNotIn('category', ['system', 'laravel', 'page_access', 'failed_action']),
            'system' => $query->where('category', 'system'),
            'laravel' => $query->where('category', 'laravel'),
            'failed' => $query->where('outcome', 'failure')->whereNotIn('category', ['system', 'laravel']),
        };
        if ($filters['q'] !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $filters['q']).'%';
            $query->where(fn ($search) => $search->where('action', 'like', $like)->orWhere('actor_name_snapshot', 'like', $like)->orWhere('target_type', 'like', $like)->orWhere('target_id', 'like', $like));
        }
        if ($filters['category'] !== '') {
            $query->where(fn ($module) => $module->where('category', $filters['category'])->orWhere('metadata_json->module', $filters['category']));
        }
        foreach (['outcome', 'severity', 'action'] as $field) {
            if ($filters[$field] !== '') {
                $query->where($field, $filters[$field]);
            }
        }
        if ($filters['actor'] !== '') {
            $query->where('actor_user_id', $filters['actor']);
        }
        if ($filters['from'] !== '') {
            $query->whereDate('created_at', '>=', $filters['from']);
        }
        if ($filters['to'] !== '') {
            $query->whereDate('created_at', '<=', $filters['to']);
        }
        $logs = $query->orderByDesc('created_at')->orderByDesc('id')->paginate(25)->withQueryString();
        $redactor = app(LogRedactor::class);

        return Inertia::render('admin/audit-log', [
            'filters' => $filters,
            'categories' => (clone $base)->distinct()->pluck('category')
                ->merge(collect(app('router')->getRoutes()->getRoutes())->map(fn ($route) => explode('.', $route->getName() ?? '')[0]))
                ->filter(fn ($category) => $category && ($request->user()->isSuperAdmin() || $category !== 'mail'))->unique()->sort()->values(),
            'actions' => (clone $base)->select('action')->distinct()->orderBy('action')->limit(300)->pluck('action')->map(fn ($action) => $redactor->text($action)),
            'actors' => (clone $base)->whereNotNull('actor_user_id')->select('actor_user_id')->selectRaw('MAX(actor_name_snapshot) as name')->groupBy('actor_user_id')->orderBy('name')->get()->map(fn ($actor) => ['id' => $actor->actor_user_id, 'name' => $redactor->text($actor->name)]),
            'logs' => [
                'data' => collect($logs->items())->map(fn (AuditLog $log) => [
                    'id' => $log->id, 'timestamp' => $log->created_at->format('d/m/Y H:i:s'),
                    'actor' => $redactor->text($log->actor_name_snapshot), 'category' => $log->category,
                    'action' => $redactor->text($log->action), 'outcome' => $log->outcome,
                    'severity' => $log->severity, 'resource' => $log->target_type, 'record_id' => $log->target_id,
                    'ip_address' => $log->ip_address, 'details' => $redactor->clean($log->metadata_json ?? []),
                ])->all(),
                'meta' => ['current_page' => $logs->currentPage(), 'last_page' => $logs->lastPage(), 'total' => $logs->total()],
            ],
        ]);
    }
}
