<?php

namespace App\Livewire\Pulse;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\View;
use Laravel\Pulse\Livewire\Card;
use Livewire\Attributes\Lazy;

#[Lazy]
class AssignmentOperationsCard extends Card
{
    public function render(): Renderable
    {
        [$metrics, $time, $runAt] = $this->remember(function () {
            $queries = $this->aggregate('assignment_queries', ['avg', 'max'])->keyBy('key');
            $failures = $this->aggregate('assignment_failure', 'count')->keyBy('key');

            $operations = $this->aggregate('assignment_operation', ['count', 'avg', 'max'], 'count', limit: 30)
                ->map(fn ($row) => (object) [
                    'route' => $row->key,
                    'count' => $row->count,
                    'average_ms' => $row->avg,
                    'slowest_ms' => $row->max,
                    'average_queries' => $queries->get($row->key)?->avg ?? 0,
                    'failures' => $failures->get($row->key)?->count ?? 0,
                ]);

            $scheduledFailures = $this->aggregate('assignment_scheduled_failure', 'count')->keyBy('key');
            $scheduled = $this->aggregate('assignment_scheduled', ['count', 'avg', 'max'])
                ->map(fn ($row) => (object) [
                    'name' => $row->key,
                    'count' => $row->count,
                    'average_ms' => $row->avg,
                    'slowest_ms' => $row->max,
                    'failures' => $scheduledFailures->get($row->key)?->count ?? 0,
                ]);

            $deliveryFailures = $this->aggregate('outbound_delivery_failure', 'count')->keyBy('key');
            $deliveries = $this->aggregate('outbound_delivery', ['count', 'avg', 'max'])
                ->map(fn ($row) => (object) [
                    'channel' => $row->key,
                    'count' => $row->count,
                    'average_ms' => $row->avg,
                    'slowest_ms' => $row->max,
                    'failures' => $deliveryFailures->get($row->key)?->count ?? 0,
                ]);

            return compact('operations', 'scheduled', 'deliveries');
        });

        return View::make('livewire.pulse.assignment-operations', [
            ...$metrics,
            'time' => $time,
            'runAt' => $runAt,
        ]);
    }
}
