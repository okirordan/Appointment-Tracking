<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\AnnotationTitle;
use App\Services\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AnnotationTitleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_if($request->user()->role === Role::Sysadmin, 403);
        $validated = $request->validate(['q' => ['required', 'string', 'min:1', 'max:255']]);
        $term = trim($validated['q']);
        $normalized = AnnotationTitle::normalize($term);
        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';

        $titles = AnnotationTitle::query()
            ->where('active', true)
            ->where(fn ($query) => $query
                ->where('shorthand', 'like', $like)
                ->orWhere('full_title', 'like', $like)
                ->orWhere('normalized_shorthand', 'like', '%'.$normalized.'%')
                ->orWhere('normalized_full_title', 'like', '%'.$normalized.'%'))
            ->orderByRaw('case when normalized_shorthand = ? then 0 when normalized_shorthand like ? then 1 else 2 end', [$normalized, $normalized.'%'])
            ->orderBy('shorthand')
            ->limit(12)
            ->get();

        return response()->json(['titles' => $titles->map(fn (AnnotationTitle $title) => $this->payload($title))]);
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        abort_if($request->user()->role === Role::Sysadmin, 403);
        $validated = $request->validate([
            'shorthand' => ['required', 'string', 'max:100', "regex:/\A[\p{L}\p{N}][\p{L}\p{N}\s\/&().,'-]*\z/u"],
            'full_title' => ['required', 'string', 'max:255', 'regex:/[A-Za-z]/'],
        ], [
            'shorthand.regex' => 'Use letters, numbers, spaces, or standard shorthand punctuation such as /, &, -, apostrophes and parentheses.',
        ]);
        $normalizedShorthand = AnnotationTitle::normalize($validated['shorthand']);
        $normalizedFullTitle = AnnotationTitle::normalize($validated['full_title']);
        $existing = AnnotationTitle::query()
            ->where('normalized_shorthand', $normalizedShorthand)
            ->orWhere('normalized_full_title', $normalizedFullTitle)
            ->first();
        if ($existing !== null) {
            return $this->existingResponse($request, $audit, $existing);
        }

        try {
            $title = DB::transaction(fn () => AnnotationTitle::create([
                ...$validated,
                'created_by_user_id' => $request->user()->id,
                'updated_by_user_id' => $request->user()->id,
                'active' => true,
            ]));
        } catch (QueryException $exception) {
            $existing = AnnotationTitle::query()
                ->where('normalized_shorthand', $normalizedShorthand)
                ->orWhere('normalized_full_title', $normalizedFullTitle)
                ->first();
            if ($existing === null) {
                report($exception);
                throw ValidationException::withMessages(['shorthand' => 'The annotation title could not be created. Please try again.']);
            }

            return $this->existingResponse($request, $audit, $existing, true);
        }

        $audit->log('settings', "Created annotation title {$title->shorthand}", $request->user(), 'AnnotationTitle', $title->id, [
            'full_title' => $title->full_title,
        ]);

        return response()->json([
            'message' => 'Annotation title created and selected.',
            'title' => $this->payload($title),
            'existing' => false,
        ], 201);
    }

    /** @return array{id: int, shorthand: string, full_title: string, label: string} */
    private function payload(AnnotationTitle $title): array
    {
        return [
            'id' => $title->id,
            'shorthand' => $title->shorthand,
            'full_title' => $title->full_title,
            'label' => "{$title->shorthand} — {$title->full_title}",
        ];
    }

    private function existingResponse(
        Request $request,
        AuditLogger $audit,
        AnnotationTitle $title,
        bool $createdConcurrently = false,
    ): JsonResponse {
        $reactivated = ! $title->active;
        if ($reactivated) {
            $title->forceFill([
                'active' => true,
                'updated_by_user_id' => $request->user()->id,
            ])->save();
            $audit->log('settings', "Reactivated annotation title {$title->shorthand}", $request->user(), 'AnnotationTitle', $title->id);
        }

        $message = $reactivated
            ? 'This annotation title already existed and has been reactivated and selected.'
            : ($createdConcurrently
                ? 'This annotation title was created by another user. The existing title has been selected.'
                : 'This annotation title already exists. The existing title has been selected.');

        return response()->json([
            'message' => $message,
            'title' => $this->payload($title),
            'existing' => true,
            'reactivated' => $reactivated,
        ]);
    }
}
