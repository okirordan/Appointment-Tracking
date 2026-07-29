<?php

namespace App\Http\Controllers\Oversight;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\ReportService;
use Illuminate\Http\Request;
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
        $from = (string) $request->query('from', '');
        $to = (string) $request->query('to', '');

        return Inertia::render('oversight/reports', [
            'from' => $from,
            'to' => $to,
            'generatedAt' => now()->format('d/m/Y H:i'),
            ...$this->reports->build($request->user(), $from, $to),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $from = (string) $request->query('from', '');
        $to = (string) $request->query('to', '');

        $rows = $this->reports->exportRows($request->user(), $from, $to);

        // RPT-007: every export is audited.
        $this->audit->log('report', 'Exported task report to CSV', $request->user(), null, null, [
            'rows' => count($rows),
            'from' => $from ?: null,
            'to' => $to ?: null,
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
}
