<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header name="Assignment operations" details="past {{ $this->periodForHumans() }}">
        <x-slot:icon><x-pulse::icons.arrows-left-right /></x-slot:icon>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand" wire:poll.5s="">
        @if ($operations->isEmpty())
            <x-pulse::no-results />
        @else
            <x-pulse::table>
                <x-pulse::thead>
                    <tr>
                        <x-pulse::th>Route</x-pulse::th>
                        <x-pulse::th class="text-right">Requests</x-pulse::th>
                        <x-pulse::th class="text-right">Average</x-pulse::th>
                        <x-pulse::th class="text-right">Slowest</x-pulse::th>
                        <x-pulse::th class="text-right">Queries / request</x-pulse::th>
                        <x-pulse::th class="text-right">Failures</x-pulse::th>
                    </tr>
                </x-pulse::thead>
                <tbody>
                    @foreach ($operations as $operation)
                        <tr wire:key="{{ $operation->route }}">
                            <x-pulse::td><code>{{ $operation->route }}</code></x-pulse::td>
                            <x-pulse::td numeric>{{ number_format($operation->count) }}</x-pulse::td>
                            <x-pulse::td numeric>{{ number_format($operation->average_ms) }} ms</x-pulse::td>
                            <x-pulse::td numeric>{{ number_format($operation->slowest_ms) }} ms</x-pulse::td>
                            <x-pulse::td numeric>{{ number_format($operation->average_queries, 1) }}</x-pulse::td>
                            <x-pulse::td numeric>{{ number_format($operation->failures) }}</x-pulse::td>
                        </tr>
                    @endforeach
                </tbody>
            </x-pulse::table>
        @endif

        @if ($scheduled->isNotEmpty())
            <h3 class="mt-6 mb-2 text-sm font-bold text-gray-700 dark:text-gray-300">Scheduled assignment notifications</h3>
            <x-pulse::table>
                <x-pulse::thead>
                    <tr>
                        <x-pulse::th>Command</x-pulse::th>
                        <x-pulse::th class="text-right">Runs</x-pulse::th>
                        <x-pulse::th class="text-right">Average</x-pulse::th>
                        <x-pulse::th class="text-right">Slowest</x-pulse::th>
                        <x-pulse::th class="text-right">Failures</x-pulse::th>
                    </tr>
                </x-pulse::thead>
                <tbody>
                    @foreach ($scheduled as $task)
                        <tr wire:key="{{ $task->name }}">
                            <x-pulse::td><code>{{ $task->name }}</code></x-pulse::td>
                            <x-pulse::td numeric>{{ number_format($task->count) }}</x-pulse::td>
                            <x-pulse::td numeric>{{ number_format($task->average_ms) }} ms</x-pulse::td>
                            <x-pulse::td numeric>{{ number_format($task->slowest_ms) }} ms</x-pulse::td>
                            <x-pulse::td numeric>{{ number_format($task->failures) }}</x-pulse::td>
                        </tr>
                    @endforeach
                </tbody>
            </x-pulse::table>
        @endif
    </x-pulse::scroll>
</x-pulse::card>
