<?php

namespace App\Services\Mail;

use App\Models\AnnotationTitle;
use App\Models\Department;
use App\Models\Division;
use App\Models\OrganizationalUnit;
use App\Models\Position;
use App\Models\RecipientAlias;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SharedTitleDirectory
{
    public function findExisting(string $shorthand, string $fullTitle): ?AnnotationTitle
    {
        $code = AnnotationTitle::normalize($shorthand);

        return AnnotationTitle::where('normalized_shorthand', $code)->first()
            ?? RecipientAlias::where('normalized_alias', $code)->whereNotNull('annotation_title_id')->first()?->annotationTitle
            ?? AnnotationTitle::where('normalized_full_title', AnnotationTitle::normalize($fullTitle))->first();
    }

    /** Keep routing aliases attached to the same titles used for recording mail. */
    public function link(RecipientAlias $alias): void
    {
        DB::transaction(function () use ($alias): void {
            $previousTitleId = $alias->annotation_title_id;
            $target = $alias->target;
            $fullTitle = match (true) {
                $target instanceof User => Str::limit(collect([$target->full_name, $target->title])->filter()->join(' · '), 255, ''),
                $target instanceof Position => $target->title,
                $target instanceof Department, $target instanceof Division, $target instanceof OrganizationalUnit => $target->name,
                default => null,
            };
            if ($fullTitle === null) {
                return;
            }

            // A code is authoritative. A second code for the same full title is
            // an alias, not another title; existing mail foreign keys stay intact.
            $title = $previousTitleId && ! $alias->wasChanged(['alias', 'target_type', 'target_id'])
                ? AnnotationTitle::find($previousTitleId)
                : null;
            $title ??= $this->findExisting($alias->alias, $fullTitle);
            $title ??= AnnotationTitle::create([
                'shorthand' => $alias->alias,
                'full_title' => $fullTitle,
                'active' => $alias->active,
                'created_by_user_id' => $alias->created_by_user_id,
                'updated_by_user_id' => $alias->updated_by_user_id,
            ]);

            $alias->forceFill(['annotation_title_id' => $title->id])->saveQuietly();
            $this->refreshAvailability($title->id, $alias->updated_by_user_id);
            if ($previousTitleId && $previousTitleId !== $title->id) {
                $this->refreshAvailability($previousTitleId, $alias->updated_by_user_id);
            }
        });
    }

    public function refreshAvailability(int $titleId, ?int $actorId): void
    {
        $title = AnnotationTitle::findOrFail($titleId);
        $active = ! $title->disabled_by_admin && $title->recipientAliases()->where('active', true)->exists();
        if ($title->active !== $active) {
            $title->update(['active' => $active, 'updated_by_user_id' => $actorId]);
        }
    }
}
