<?php

namespace App\Services;

use App\Models\MailRecord;
use Illuminate\Database\Eloquent\Builder;

class MailIndexedSearch
{
    /** @param Builder<MailRecord> $query */
    public function apply(Builder $query, string $term): Builder
    {
        $term = trim(preg_replace('/\s+/u', ' ', $term) ?? $term);
        if ($query->getConnection()->getDriverName() !== 'mysql') {
            return $query->matchingKeywords($term);
        }

        // Date and financial-year searches already use ordinary B-tree indexes.
        if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$|^\d{4}-\d{2}-\d{2}$|^\d{4}\/\d{2}$/', $term) === 1) {
            return $query->matchingKeywords($term);
        }

        $tokens = array_values(array_unique(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($term)) ?: [],
            fn (string $token) => mb_strlen($token) >= 3,
        )));
        if ($tokens === [] && preg_match('/[\p{L}\p{N}][\/-][\p{L}\p{N}]/u', $term) === 1) {
            // MySQL ignores one- and two-character words in this index. The
            // legacy matcher must handle these references until an ngram
            // index can preserve its substring semantics.
            return $query->matchingKeywords($term);
        }
        if ($tokens === []) {
            return $query->matchingKeywords($term);
        }
        $booleanTerm = implode(' ', array_map(fn (string $token) => '+'.$token.'*', $tokens));

        return $query->whereFullText('mail_records.search_text', $booleanTerm, ['mode' => 'boolean']);
    }
}
