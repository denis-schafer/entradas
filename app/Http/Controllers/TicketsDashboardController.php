<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TicketsDashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $events = DB::table('tickets_events as e');
        $orders = DB::table('tickets_orders as o');

        if ($eventId = $request->query('event_id')) {
            $events->where('id', (int) $eventId);
            $orders->where('event_id', (int) $eventId);
        }

        $totalEvents = (clone $events)->count();
        $publishedEvents = (clone $events)->where('status', 'published')->count();

        $pendingOrders = (clone $orders)->where('status', 'pending')->count();
        $paidOrders = (clone $orders)->where('status', 'paid')->count();
        $totalRevenue = (float) (clone $orders)->where('status', 'paid')->sum('total');

        $scanQuery = DB::table('tickets_scans')
            ->where('scanned_at', '>=', now()->subDays(30));

        if ($eventId = $request->query('event_id')) {
            $scanQuery->where('event_id', (int) $eventId);
        }

        $recentOrders = (clone $orders)
            ->leftJoin('tickets_events as e', 'o.event_id', '=', 'e.id')
            ->select([
                'o.id', 'o.buyer_name', 'o.total', 'o.status',
                'o.payment_mode', 'o.installment_count', 'o.created_at', 'o.paid_at',
                'o.event_id', 'e.name as event_name',
            ])
            ->orderByDesc('o.id')
            ->limit(10)
            ->get();

        $upcomingEvents = (clone $events)
            ->where('status', 'published')
            ->whereNotNull('starts_at')
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at')
            ->limit(5)
            ->get(['id', 'name', 'starts_at', 'location', 'cover_image']);

        return response()->json([
            'events' => [
                'total' => $totalEvents,
                'published' => $publishedEvents,
            ],
            'orders' => [
                'pending' => $pendingOrders,
                'paid' => $paidOrders,
            ],
            'revenue' => $totalRevenue,
            'scans_last_30_days' => (clone $scanQuery)->count(),
            'recent_orders' => $recentOrders,
            'upcoming_events' => $upcomingEvents,
        ]);
    }
}