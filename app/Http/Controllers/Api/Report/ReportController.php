<?php

namespace App\Http\Controllers\Api\Report;

use App\Http\Controllers\Controller;
use App\Models\Charge;
use App\Models\Guest;
use App\Models\HousekeepingTask;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    /**
     * GET /api/reports/occupancy
     */
    public function occupancy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
        ]);

        $startDate  = $validated['start_date'];
        $endDate    = $validated['end_date'];
        $totalRooms = Room::where('is_active', true)->count();

        $occupancyData = Reservation::select(
            DB::raw('DATE(check_in_date) as date'),
            DB::raw('COUNT(DISTINCT room_id) as occupied_rooms')
        )
            ->where('check_in_date', '>=', $startDate)
            ->where('check_out_date', '<=', $endDate)
            ->whereIn('status', ['confirmed', 'checked_in'])
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $data = $occupancyData->map(function ($item) use ($totalRooms) {
            $occupancyRate = $totalRooms > 0 ? ($item->occupied_rooms / $totalRooms) * 100 : 0;
            return [
                'date'           => $item->date,
                'occupied_rooms' => $item->occupied_rooms,
                'total_rooms'    => $totalRooms,
                'occupancy_rate' => round($occupancyRate, 2),
            ];
        });

        return response()->json([
            'data'    => $data,
            'summary' => [
                'average_occupancy' => $data->avg('occupancy_rate'),
                'total_rooms'       => $totalRooms,
            ],
        ]);
    }

    /**
     * GET /api/reports/revenue
     */
    public function revenue(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
        ]);

        $startDate = $validated['start_date'];
        $endDate   = $validated['end_date'];

        // Revenue is based on actual completed payments minus refunds
        $revenueData = Payment::select(
            DB::raw('DATE(created_at) as date'),
            DB::raw('SUM(CASE WHEN status = "completed" THEN amount ELSE 0 END) as completed_payments'),
            DB::raw('SUM(CASE WHEN status = "refunded" THEN amount ELSE 0 END) as refunded_payments'),
            DB::raw('SUM(CASE WHEN status = "completed" THEN amount ELSE 0 END) - SUM(CASE WHEN status = "refunded" THEN amount ELSE 0 END) as revenue')
        )
            ->where('created_at', '>=', $startDate)
            ->where('created_at', '<=', $endDate . ' 23:59:59')
            ->whereIn('status', ['completed', 'refunded'])
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $totalRevenue = $revenueData->sum('revenue');
        $totalCompleted = $revenueData->sum('completed_payments');
        $totalRefunded = $revenueData->sum('refunded_payments');

        return response()->json([
            'data'    => $revenueData,
            'summary' => [
                'total_revenue'         => $totalRevenue,
                'total_completed'       => $totalCompleted,
                'total_refunded'       => $totalRefunded,
                'average_daily_revenue' => $revenueData->count() > 0 ? $totalRevenue / $revenueData->count() : 0,
            ],
        ]);
    }

    /**
     * GET /api/reports/adr
     */
    public function adr(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
        ]);

        $startDate    = $validated['start_date'];
        $endDate      = $validated['end_date'];

        // Get reservations that were checked in during the period
        $reservations = Reservation::where('check_in_date', '>=', $startDate)
            ->where('check_in_date', '<=', $endDate)
            ->whereIn('status', ['checked_in', 'checked_out'])
            ->get();

        // Get actual payments for these reservations (net revenue)
        $reservationIds = $reservations->pluck('id');
        $totalRevenue = Payment::whereIn('reservation_id', $reservationIds)
            ->where('status', 'completed')
            ->sum('amount');

        $totalRoomsSold = $reservations->count();
        $adr            = $totalRoomsSold > 0 ? $totalRevenue / $totalRoomsSold : 0;

        return response()->json([
            'data' => [
                'start_date'    => $startDate,
                'end_date'      => $endDate,
                'total_revenue' => $totalRevenue,
                'rooms_sold'    => $totalRoomsSold,
                'adr'           => round($adr, 2),
            ],
        ]);
    }

    /**
     * GET /api/reports/revpar
     */
    public function revpar(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
        ]);

        $startDate  = $validated['start_date'];
        $endDate    = $validated['end_date'];
        $totalRooms = Room::where('is_active', true)->count();
        $totalDays  = max(1, (strtotime($endDate) - strtotime($startDate)) / 86400 + 1);
        $availableRoomNights = $totalRooms * $totalDays;

        // Get actual payments for the period (net revenue)
        $totalRevenue = Payment::where('created_at', '>=', $startDate)
            ->where('created_at', '<=', $endDate . ' 23:59:59')
            ->where('status', 'completed')
            ->sum('amount');

        $revpar = $availableRoomNights > 0 ? $totalRevenue / $availableRoomNights : 0;

        return response()->json([
            'data' => [
                'start_date'            => $startDate,
                'end_date'              => $endDate,
                'total_revenue'         => $totalRevenue,
                'total_rooms'           => $totalRooms,
                'total_days'            => $totalDays,
                'available_room_nights' => $availableRoomNights,
                'revpar'                => round($revpar, 2),
            ],
        ]);
    }

    /**
     * GET /api/reports/arrivals
     */
    public function arrivals(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
        ]);

        $startDate = $validated['start_date'];
        $endDate   = $validated['end_date'];

        $arrivals = Reservation::with(['guest', 'room.roomType'])
            ->where('check_in_date', '>=', $startDate)
            ->where('check_in_date', '<=', $endDate)
            ->whereIn('status', ['confirmed', 'checked_in'])
            ->orderBy('check_in_date')
            ->get();

        return response()->json([
            'data'    => $arrivals,
            'summary' => [
                'total_arrivals' => $arrivals->count(),
                'by_status'      => $arrivals->groupBy('status')->map->count(),
            ],
        ]);
    }

    /**
     * GET /api/reports/departures
     */
    public function departures(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
        ]);

        $startDate = $validated['start_date'];
        $endDate   = $validated['end_date'];

        $departures = Reservation::with(['guest', 'room.roomType'])
            ->where('check_out_date', '>=', $startDate)
            ->where('check_out_date', '<=', $endDate)
            ->whereIn('status', ['checked_in', 'checked_out'])
            ->orderBy('check_out_date')
            ->get();

        return response()->json([
            'data'    => $departures,
            'summary' => [
                'total_departures' => $departures->count(),
                'by_status'        => $departures->groupBy('status')->map->count(),
            ],
        ]);
    }

    /**
     * GET /api/reports/guests
     */
    public function guests(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
        ]);

        $startDate = $validated['start_date'];
        $endDate   = $validated['end_date'];

        $guests = Guest::withCount(['reservations' => function ($query) use ($startDate, $endDate) {
            $query->where('check_in_date', '>=', $startDate)
                  ->where('check_in_date', '<=', $endDate);
        }])
            ->whereHas('reservations', function ($query) use ($startDate, $endDate) {
                $query->where('check_in_date', '>=', $startDate)
                      ->where('check_in_date', '<=', $endDate);
            })
            ->get();

        return response()->json([
            'data'    => $guests,
            'summary' => [
                'total_guests' => $guests->count(),
                'vip_guests'   => $guests->where('vip_status', true)->count(),
                'new_guests'   => $guests->where('created_at', '>=', $startDate)->count(),
            ],
        ]);
    }

    /**
     * GET /api/reports/housekeeping
     */
    public function housekeeping(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
        ]);

        $startDate = $validated['start_date'];
        $endDate   = $validated['end_date'];

        $tasks = HousekeepingTask::where('created_at', '>=', $startDate)
            ->where('created_at', '<=', $endDate)
            ->with(['room', 'assignedTo'])
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'data'    => $tasks,
            'summary' => [
                'total_tasks' => $tasks->count(),
                'by_status'   => $tasks->groupBy('status')->map->count(),
                'by_priority' => $tasks->groupBy('priority')->map->count(),
            ],
        ]);
    }

    /**
     * GET /api/reports/financial-summary
     */
    public function financialSummary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
        ]);

        $startDate = $validated['start_date'];
        $endDate   = $validated['end_date'];

        // Total charges = value of all services provided
        $totalCharges = Charge::where('created_at', '>=', $startDate)
            ->where('created_at', '<=', $endDate . ' 23:59:59')
            ->sum('amount');

        // Total payments = actual money received
        $totalPayments = Payment::where('created_at', '>=', $startDate)
            ->where('created_at', '<=', $endDate . ' 23:59:59')
            ->where('status', 'completed')
            ->sum('amount');

        // Refunds = money returned
        $refundedPayments = Payment::where('created_at', '>=', $startDate)
            ->where('created_at', '<=', $endDate . ' 23:59:59')
            ->where('status', 'refunded')
            ->sum('amount');

        // Pending payments = money expected but not yet received
        $pendingPayments = Payment::where('created_at', '>=', $startDate)
            ->where('created_at', '<=', $endDate . ' 23:59:59')
            ->where('status', 'pending')
            ->sum('amount');

        // Net income = charges minus refunds (actual value after returns)
        $netIncome = $totalCharges - $refundedPayments;

        return response()->json([
            'data' => [
                'start_date'        => $startDate,
                'end_date'          => $endDate,
                'total_charges'     => $totalCharges,
                'total_payments'     => $totalPayments,
                'pending_payments'  => $pendingPayments,
                'refunded_payments' => $refundedPayments,
                'net_income'        => $netIncome,
            ],
        ]);
    }
}
