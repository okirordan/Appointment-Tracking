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
        [$operations, $time, $runAt] = $this->remember(function () {
            $queries = $this->aggregate('assignment_queries', ['avg', 'max'])->keyBy('key');
            $failures = $this->aggregate('assignment_failure', 'count')->keyBy('key');

            return $this->aggregate('assignment_operation', ['count', 'avg', 'max'], 'count', limit: 30)
                ->map(fn ($row) => (object) [
                    'route' => $row->key,
                    'count' => $row->count,
                    'average_ms' => $row->avg,
                    'slowest_ms' => $row->max,
                    'average_queries' => $queries->get($row->key)?->avg ?? 0,
                    'failures' => $failures->get($row->key)?->count ?? 0,
                ]);
        });

        $failures = $this->aggregate('assignment_scheduled_failure', 'count')->keyBy('key');
        $scheduled = $this->aggregate('assignment_scheduled', ['count', 'avg', 'max'])
            ->map(fn ($row) => (object) [
                'name' => $row->key,
                'count' => $row->count,
                'average_ms' => $row->avg,
                'slowest_ms' => $row->max,
                'failures' => $failures->get($row->key)?->count ?? 0,
            ]);

        return View::make('livewire.pulse.assignment-operations', compact('operations', 'scheduled', 'time', 'runAt'));
    }
}
