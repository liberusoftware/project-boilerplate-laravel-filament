<?php

namespace Liberu\Foundation\ApplicationCore\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Liberu\Foundation\ApplicationCore\Health\ReadinessRegistry;

final class ReadinessController
{
    public function __invoke(ReadinessRegistry $registry): JsonResponse
    {
        $checks = $registry->report();
        $ready = ! in_array(false, $checks, true);

        return response()->json(['status' => $ready ? 'ready' : 'unavailable', 'checks' => $checks, 'release' => config('application-core.release')], $ready ? 200 : 503);
    }
}
