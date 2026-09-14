<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\AnnotationTitleController;
use App\Http\Controllers\Controller;
use App\Models\AnnotationTitle;
use App\Models\RecipientAlias;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SharedTitleController extends Controller
{
    public function store(Request $request, AnnotationTitleController $titles, AuditLogger $audit): RedirectResponse
    {
        $response = $titles->store($request, $audit);

        return back()->with('success', $response->getData(true)['message']);
    }

    public function update(Request $request, AnnotationTitle $annotationTitle, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'shorthand' => ['required', 'string', 'max:100', "regex:/\A[\p{L}\p{N}][\p{L}\p{N}\s\/&().,'-]*\z/u"],
            'full_title' => ['required', 'string', 'max:255', 'regex:/[A-Za-z]/'],
        ]);
        $code = AnnotationTitle::normalize($validated['shorthand']);
        $fullTitle = AnnotationTitle::normalize($validated['full_title']);
        $conflict = AnnotationTitle::whereKeyNot($annotationTitle->id)
            ->where(fn ($query) => $query->where('normalized_shorthand', $code)->orWhere('normalized_full_title', $fullTitle))->exists();
        $aliasConflict = RecipientAlias::where('normalized_alias', $code)
            ->whereNotNull('annotation_title_id')->where('annotation_title_id', '!=', $annotationTitle->id)->exists();
        if ($conflict || $aliasConflict) {
            throw ValidationException::withMessages(['shorthand' => 'This designation or abbreviation already belongs to another shared title.']);
        }
        $before = $annotationTitle->only('shorthand', 'full_title');
        $annotationTitle->update([...$validated, 'updated_by_user_id' => $request->user()->id]);
        $audit->log('settings', "Updated shared title {$annotationTitle->shorthand}", $request->user(), 'AnnotationTitle', $annotationTitle->id, [
            'before' => $before, 'after' => $annotationTitle->only('shorthand', 'full_title'),
        ]);

        return back()->with('success', 'Shared title updated for recording and forwarding correspondence.');
    }

    public function toggle(Request $request, AnnotationTitle $annotationTitle, AuditLogger $audit): RedirectResponse
    {
        DB::transaction(function () use ($request, $annotationTitle): void {
            $annotationTitle->refresh();
            $annotationTitle->update([
                'active' => ! $annotationTitle->active,
                'disabled_by_admin' => $annotationTitle->active,
                'updated_by_user_id' => $request->user()->id,
            ]);
        });
        $action = $annotationTitle->active ? 'Activated' : 'Deactivated';
        $audit->log('settings', "{$action} shared title {$annotationTitle->shorthand}", $request->user(), 'AnnotationTitle', $annotationTitle->id);

        return back()->with('success', "{$action} shared title and its availability in mail searches.");
    }
}
