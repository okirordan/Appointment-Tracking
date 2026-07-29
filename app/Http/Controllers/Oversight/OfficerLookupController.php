<?php

namespace App\Http\Controllers\Oversight;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PerformanceService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OfficerLookupController extends Controller
{
    public function __construct(private PerformanceService $performance) {}

    /**
     * Officer Lookup (PRD §12.16): search from two characters, summary
     * counts per officer, expandable portfolio for one selected officer.
     */
    public function __invoke(Request $request): Response
    {
        $viewer = $request->user();
        $term = trim((string) $request->query('q', ''));
        $selectedId = $request->integer('officer');

        $officers = [];
        if (mb_strlen($term) >= (int) config('ats.search.min_chars')) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

            $officers = $this->performance->visibleOfficers($viewer)
                ->where(fn ($query) => $query
                    ->where('full_name', 'like', $like)
                    ->orWhere('title', 'like', $like))
                ->orderBy('full_name')
                ->limit(20)
                ->get()
                ->map(fn (User $officer) => [
                    ...$this->performance->metricsFor($officer),
                    'initials' => $officer->initials(),
                ])->all();
        }

        $selected = null;
        if ($selectedId > 0) {
            $officer = User::find($selectedId);
            if ($officer !== null && $this->performance->canViewOfficer($viewer, $officer)) {
                $selected = [
                    ...$this->performance->metricsFor($officer),
                    'initials' => $officer->initials(),
                    ...$this->performance->portfolio($officer),
                ];
            }
        }

        return Inertia::render('oversight/officer-lookup', [
            'q' => $term,
            'officers' => $officers,
            'selected' => $selected,
        ]);
    }
}
