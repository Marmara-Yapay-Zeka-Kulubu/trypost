<?php

declare(strict_types=1);

namespace App\Marmara\Members\Http;

use App\Http\Controllers\Controller;
use App\Marmara\Members\Actions\BuildMemberPostStats;
use App\Support\RequestIds;
use App\Support\Timezone;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Insights page's "Üye bazlı paylaşımlar" card: per member post counts
 * for the page's period (dates in the viewer's time zone) and channels.
 */
class MemberPostStatsController extends Controller
{
    public const int MAX_DAYS = 400;

    public function __invoke(Request $request, BuildMemberPostStats $stats): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace;
        $this->authorize('view', $workspace);

        $validated = $request->validate([
            'start' => ['required', 'date_format:Y-m-d'],
            'end' => ['required', 'date_format:Y-m-d', 'after_or_equal:start'],
            'channels' => ['sometimes', 'array'],
        ]);

        $timezone = Timezone::normalize($request->user()->timezone);
        $start = CarbonImmutable::createFromFormat('Y-m-d', $validated['start'], $timezone)->startOfDay();
        $end = CarbonImmutable::createFromFormat('Y-m-d', $validated['end'], $timezone)->endOfDay();

        if ($start->diffInDays($end) > self::MAX_DAYS) {
            $start = $end->subDays(self::MAX_DAYS)->startOfDay();
        }

        $requested = RequestIds::uuidList($request->collect('channels')->unique());
        $channelIds = $requested === []
            ? null
            : $workspace->socialAccounts()->whereKey($requested)->pluck('id')->map(fn (mixed $id): string => (string) $id)->all();

        return response()->json([
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'members' => $stats->handle($workspace, $start, $end, $channelIds),
        ]);
    }
}
