<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    /**
     * Audit Log (PRD §12.19): admin-only, immutable, filterable by
     * category, outcome, free text, and date range.
     */
    public function __invoke(Request $request): Response
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'category' => (string) $request->query('category', ''),
            'outcome' => (string) $request->query('outcome', ''),
            'from' => (string) $request->query('from', ''),
            'to' => (string) $request->query('to', ''),
        ];

        $query = AuditLog::query()->orderByDesc('created_at');

        if ($filters['q'] !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $filters['q']).'%';
            $query->where(fn ($q) => $q
                ->where('action', 'like', $like)
                ->orWhere('actor_name_snapshot', 'like', $like));
        }
        if ($filters['category'] !== '') {
            $query->where('category', $filters['category']);
        }
        if ($filters['outcome'] !== '') {
            $query->where('outcome', $filters['outcome']);
        }
        if ($filters['from'] !== '') {
            $query->whereDate('created_at', '>=', $filters['from']);
        }
        if ($filters['to'] !== '') {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        $logs = $query->paginate(25)->withQueryString();

        return Inertia::render('admin/audit-log', [
            'filters' => $filters,
            'categories' => AuditLog::query()->select('category')->distinct()->orderBy('category')->pluck('category'),
            'logs' => [
                'data' => collect($logs->items())->map(fn (AuditLog $log) => [
                    'id' => $log->id,
                    'timestamp' => $log->created_at->format('d/m/Y H:i:s'),
                    'actor' => $log->actor_name_snapshot,
                    'category' => $log->category,
                    'action' => $log->action,
                    'outcome' => $log->outcome,
                    'ip_address' => $log->ip_address,
                ])->all(),
                'meta' => [
                    'current_page' => $logs->currentPage(),
                    'last_page' => $logs->lastPage(),
                    'total' => $logs->total(),
                ],
            ],
        ]);
    }
}
