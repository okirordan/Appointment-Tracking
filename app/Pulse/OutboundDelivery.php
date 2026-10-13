<?php

namespace App\Pulse;

use Closure;
use Laravel\Pulse\Facades\Pulse;
use Throwable;

/** Measure existing external delivery calls without destination or payload data. */
class OutboundDelivery
{
    public static function measure(string $channel, Closure $callback): mixed
    {
        $startedAt = hrtime(true);

        try {
            return $callback();
        } catch (Throwable $exception) {
            Pulse::rescue(fn () => Pulse::record('outbound_delivery_failure', $channel)->count());

            throw $exception;
        } finally {
            $duration = max(0, intdiv(hrtime(true) - $startedAt, 1_000_000));
            Pulse::rescue(fn () => Pulse::record('outbound_delivery', $channel, $duration)->count()->avg()->max());
        }
    }
}
