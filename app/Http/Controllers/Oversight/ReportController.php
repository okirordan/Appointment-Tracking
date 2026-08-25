<?php

namespace App\Http\Controllers\Oversight;

use App\Enums\Priority;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(
        private ReportService $reports,
        private AuditLogger $audit,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);

        return Inertia::render('oversight/reports', [
            'filters' => $filters,
            ...$this->filterOptions($request->user()),
            'generatedAt' => now()->format('d/m/Y H:i'),
            ...$this->reports->build($request->user(), $filters),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);

        $rows = $this->reports->exportRows($request->user(), $filters);

        // RPT-007: every export is audited.
        $this->audit->log('report', 'Exported task report to CSV', $request->user(), null, null, [
            'rows' => count($rows),
            'parameters' => array_filter($filters, fn (string $value) => $value !== ''),
        ]);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');

            $header = $rows === []
                ? ['Reference', 'Title', 'Level', 'Assignee', 'Department', 'Priority', 'Due Date', 'Status', 'Progress', 'Created At', 'Completed At']
                : array_keys($rows[0]);

            fputcsv($out, $header);

            foreach ($rows as $row) {
                fputcsv($out, array_map($this->escapeCell(...), array_values($row)));
            }

            fclose($out);
        }, 'ats-report-'.now()->format('Ymd-Hi').'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * RPT-006: neutralise spreadsheet formula injection by prefixing
     * dangerous leading characters with a quote.
     */
    private function escapeCell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }

    /** @return array{from: string, to: string, department: string, officer: string, status: string, priority: string, timeliness: string} */
    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'department' => ['nullable', 'integer', Rule::exists('departments', 'id')->withoutTrashed()],
            'officer' => ['nullable', 'integer', Rule::exists('users', 'id')->withoutTrashed()],
            'status' => ['nullable', Rule::enum(TaskStatus::class)],
            'priority' => ['nullable', Rule::enum(Priority::class)],
            'timeliness' => ['nullable', Rule::in(['overdue', 'due_soon', 'no_due_date'])],
        ]);

        return collect(['from', 'to', 'department', 'officer', 'status', 'priority', 'timeliness'])
            ->mapWithKeys(fn (string $key) => [$key => isset($validated[$key]) ? (string) $validated[$key] : ''])
            ->all();
    }

    /** @return array<string, mixed> */
    private function filterOptions(User $viewer): array
    {
        $departmentOptions = Department::query()
            ->when(
                in_array($viewer->role, [Role::Commissioner, Role::Secretary], true),
                fn ($query) => $query->whereKey($viewer->department_id),
            )
            ->orderBy('name')
            ->get(['id', 'name', 'active']);

        $officerOptions = User::query()
            ->when(
                in_array($viewer->role, [Role::Commissioner, Role::Secretary], true),
                fn ($query) => $query->where('department_id', $viewer->department_id),
            )
            ->whereIn('role', [Role::Officer->value, Role::Secretary->value, Role::Commissioner->value, Role::Clerk->value])
            ->with('department:id,name')
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'department_id', 'active'])
            ->map(fn (User $officer) => [
                'id' => $officer->id,
                'name' => $officer->full_name,
                'department_id' => $officer->department_id,
                'department_name' => $officer->department?->name ?? 'Central / Office of the PS',
                'active' => $officer->active,
            ]);

        return [
            'departmentOptions' => $departmentOptions,
            'officerOptions' => $officerOptions,
            'statusOptions' => collect(TaskStatus::cases())->map(fn (TaskStatus $status) => [
                'value' => $status->value,
                'label' => $status->label(),
            ]),
            'priorityOptions' => collect(Priority::cases())->map(fn (Priority $priority) => [
                'value' => $priority->value,
                'label' => $priority->label(),
            ]),
        ];
    }
}
