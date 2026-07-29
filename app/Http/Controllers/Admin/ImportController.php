<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ImportBatch;
use App\Services\Imports\ImportSchemaRegistry;
use App\Services\Imports\StagedImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportController extends Controller
{
    public function __construct(private StagedImportService $imports, private ImportSchemaRegistry $schemas) {}

    public function index(): Response
    {
        return Inertia::render('admin/imports/index', [
            'batches' => ImportBatch::with('initiatedBy:id,full_name')->orderByDesc('created_at')->paginate(20),
            'entities' => array_keys($this->schemas->schemas()),
            'entityLabels' => $this->schemas->labels(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $v = $request->validate([
            'source_system' => ['required', 'string', 'max:100'],
            'entity_type' => ['required', Rule::in(array_keys($this->schemas->schemas()))],
            'file' => ['required', 'file', 'mimes:csv,xlsx', 'max:20480'],
        ]);
        try {
            $batch = $this->imports->stage($request->user(), $v['file'], $v['source_system'], $v['entity_type']);

            return redirect()->route('admin.imports.show', $batch)->with('success', 'File staged. Review validation before confirmation.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function show(ImportBatch $batch): Response
    {
        return Inertia::render('admin/imports/show', ['batch' => $batch, 'rows' => $batch->rows()->orderBy('row_number')->paginate(50)]);
    }

    public function confirm(Request $request, ImportBatch $batch): RedirectResponse
    {
        try {
            $this->imports->confirm($batch, $request->user());

            return back()->with('success', 'Import completed transactionally.');
        } catch (\Throwable $e) {
            return back()->with('error', 'Import rolled back: '.$e->getMessage());
        }
    }

    public function template(Request $request): StreamedResponse
    {
        $entity = $request->validate([
            'entity' => ['required', Rule::in(array_keys($this->schemas->schemas()))],
        ])['entity'];
        $headers = $this->schemas->schemas()[$entity];
        $headerStyle = (new Style)
            ->setFontBold()
            ->setFontColor(Color::WHITE)
            ->setBackgroundColor('155DFC');

        return response()->streamDownload(function () use ($headers, $headerStyle): void {
            $writer = new Writer;
            $writer->openToFile('php://output');
            $writer->addRow(Row::fromValues($headers, $headerStyle));
            $writer->close();
        }, 'ats-'.str_replace('_', '-', $entity).'-import-template.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
