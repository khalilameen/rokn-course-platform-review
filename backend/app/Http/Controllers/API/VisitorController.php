<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Visitor;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class VisitorController extends Controller
{
    public function getStats(Request $request): JsonResponse
    {
        $today = Carbon::today();
        $thisWeek = Carbon::now()->startOfWeek();
        $thisMonth = Carbon::now()->startOfMonth();

        $stats = [
            'total_visitors' => Visitor::count(),
            'today_visitors' => Visitor::whereDate('visited_at', $today)->count(),
            'this_week_visitors' => Visitor::where('visited_at', '>=', $thisWeek)->count(),
            'this_month_visitors' => Visitor::where('visited_at', '>=', $thisMonth)->count(),
            'unique_visitors_today' => Visitor::whereDate('visited_at', $today)
                ->distinct('ip_address')
                ->count('ip_address'),
            'browser_stats' => Visitor::select('browser', DB::raw('count(*) as count'))
                ->groupBy('browser')
                ->orderBy('count', 'desc')
                ->get(),
            'os_stats' => Visitor::select('operating_system', DB::raw('count(*) as count'))
                ->groupBy('operating_system')
                ->orderBy('count', 'desc')
                ->get(),
            'device_stats' => Visitor::select('device_type', DB::raw('count(*) as count'))
                ->groupBy('device_type')
                ->orderBy('count', 'desc')
                ->get(),
        ];

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'Visitor statistics retrieved successfully',
            'data' => $stats,
        ]);
    }

    public function getRecentVisitors(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => 'nullable|integer|min:1|max:100',
        ]);
        $visitors = Visitor::query()
            ->select([
                'id',
                'browser',
                'operating_system',
                'device_type',
                'visited_at',
            ])
            ->orderByDesc('visited_at')
            ->limit((int) ($validated['limit'] ?? 50))
            ->get();

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'Recent visitor activity retrieved successfully',
            'data' => $visitors,
        ]);
    }
}
