<?php

namespace App\Services\Performance;

class PerformanceExplanationService
{
    public function explain(array $metrics, ?float $peerCompletionRate): array
    {
        if ($metrics['assigned'] === 0) {
            return [['state' => 'insufficient_data', 'text' => 'No assignments occurred during this period.']];
        }
        if (! $metrics['eligible_for_rank']) {
            return [['state' => 'insufficient_data', 'text' => "Insufficient data: only {$metrics['assigned']} assignment".($metrics['assigned'] === 1 ? '' : 's').' occurred during this period.']];
        }
        $items = [];
        if ($peerCompletionRate !== null && abs($metrics['completion_rate'] - $peerCompletionRate) >= 5) {
            $direction = $metrics['completion_rate'] > $peerCompletionRate ? 'Higher' : 'Lower';
            $items[] = ['state' => 'comparison', 'text' => sprintf('%s completion rate: %.1f%% compared with the peer average of %.1f%%.', $direction, $metrics['completion_rate'], $peerCompletionRate)];
        }
        if ($metrics['late_completed'] > 0) {
            $items[] = ['state' => 'fact', 'text' => "{$metrics['late_completed']} completed assignment".($metrics['late_completed'] === 1 ? ' was' : 's were').' submitted after the due date.'];
        }
        if ($metrics['high_priority_overdue'] > 0) {
            $items[] = ['state' => 'fact', 'text' => "{$metrics['high_priority_overdue']} high-priority assignment".($metrics['high_priority_overdue'] === 1 ? ' remains' : 's remain').' overdue.'];
        }
        if ($items === []) {
            $items[] = ['state' => 'fact', 'text' => "{$metrics['completed']} of {$metrics['assigned']} assignments were completed during the measured cohort."];
        }

        return $items;
    }
}
