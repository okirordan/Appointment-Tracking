<?php

namespace App\Services\Mail;

use App\Models\AnnotationTitle;
use App\Models\CorrespondenceOfficeAlias;
use App\Models\Position;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CorrespondenceOfficeDirectory
{
    public function suggestions(string $term): Collection
    {
        $term = trim($term);
        $like = '%'.addcslashes($term, '%_\\').'%';
        $normalized = AnnotationTitle::normalize($term);

        $titles = AnnotationTitle::query()->where('active', true)
            ->where(fn ($query) => $query->where('shorthand', 'like', $like)
                ->orWhere('full_title', 'like', $like)
                ->when($normalized !== '', fn ($query) => $query
                    ->orWhere('normalized_shorthand', 'like', '%'.$normalized.'%')
                    ->orWhere('normalized_full_title', 'like', '%'.$normalized.'%')))
            ->orderBy('shorthand')->limit(12)->get()
            ->map(fn ($title) => ['label' => "{$title->shorthand} — {$title->full_title}", 'value' => $title->full_title]);

        $positions = Position::query()->where('active', true)->where('title', 'like', $like)
            ->orderBy('title')->limit(12)->pluck('title')
            ->map(fn ($title) => ['label' => $title, 'value' => $title]);

        $aliases = CorrespondenceOfficeAlias::query()->where('name', 'like', $like)
            ->orderBy('name')->limit(12)->pluck('name')
            ->map(fn ($name) => ['label' => $name, 'value' => $name]);

        return $titles->concat($positions)->concat($aliases)
            ->unique(fn ($office) => Str::lower($office['value']))->take(12)->values();
    }

    public function resolveOrRemember(string $name, User $user, string $kind = 'office'): string
    {
        $name = preg_replace('/\s+/u', ' ', trim($name)) ?? trim($name);
        $normalizedTitle = AnnotationTitle::normalize($name);
        $title = $normalizedTitle === '' ? null : AnnotationTitle::query()->where('active', true)
            ->where(fn ($query) => $query->where('normalized_shorthand', $normalizedTitle)
                ->orWhere('normalized_full_title', $normalizedTitle))->first();
        if ($title) {
            return $title->full_title;
        }

        $position = Position::query()->where('active', true)->where('title', $name)->first();
        if ($position) {
            return $position->title;
        }

        $alias = CorrespondenceOfficeAlias::query()->firstOrCreate(
            ['normalized_name' => Str::lower($name)],
            ['name' => $name, 'kind' => $kind, 'created_by_user_id' => $user->id],
        );

        return $alias->name;
    }

    public function rememberRecipient(string $name, User $user, string $kind = 'office'): CorrespondenceOfficeAlias
    {
        $name = preg_replace('/\s+/u', ' ', trim($name)) ?? trim($name);

        return CorrespondenceOfficeAlias::query()->firstOrCreate(
            ['normalized_name' => Str::lower($name)],
            ['name' => $name, 'kind' => $kind, 'created_by_user_id' => $user->id],
        );
    }
}
