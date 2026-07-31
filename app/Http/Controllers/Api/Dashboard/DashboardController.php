<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Charge;
use App\Models\Folio;
use App\Models\Payment;
use App\Models\Reservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Return real KPI data for the dashboard overview (today's data).
     */
    public function kpis(): JsonResponse
    {
        $today = now()->startOfDay();

        // Total reservations created today
        $totalReservations = Reservation::whereDate('created_at', $today)->count();

        // Today's check-ins (reservations with check_in_date = today)
        $todaysCheckins = Reservation::whereDate('check_in_date', $today)
            ->whereIn('status', ['checked_in', 'checked_out'])
            ->count();

        // Today's check-outs (reservations with check_out_date = today)
        $todaysCheckouts = Reservation::whereDate('check_out_date', $today)
            ->where('status', 'checked_out')
            ->count();

        // Total revenue today (paid folios - completed payments)
        $paidRevenue = Payment::whereDate('created_at', $today)
            ->where('status', 'completed')
            ->sum('amount');

        // Unpaid folios balance today (charges minus payments for open folios)
        $unpaidBalance = Folio::whereDate('created_at', $today)
            ->where('status', 'open')
            ->withSum('charges as total_charges', 'amount')
            ->withSum('payments as total_payments', 'amount')
            ->get()
            ->sum(function ($folio) {
                $charges = $folio->total_charges ?? 0;
                $payments = $folio->total_payments ?? 0;
                return max(0, $charges - $payments);
            });

        // Total revenue including paid and unpaid
        $totalRevenue = $paidRevenue + $unpaidBalance;

        return response()->json([
            'data' => [
                [
                    'key'        => 'total_reservations',
                    'label'      => 'Total Reservations',
                    'value'      => $totalReservations,
                    'period'     => 'Today',
                ],
                [
                    'key'        => 'todays_checkins',
                    'label'      => "Today's Check-ins",
                    'value'      => $todaysCheckins,
                    'period'     => 'Today',
                ],
                [
                    'key'        => 'todays_checkouts',
                    'label'      => "Today's Check-outs",
                    'value'      => $todaysCheckouts,
                    'period'     => 'Today',
                ],
                [
                    'key'        => 'total_revenue',
                    'label'      => 'Total Revenue',
                    'value'      => round($totalRevenue, 2),
                    'paid_revenue' => round($paidRevenue, 2),
                    'unpaid_balance' => round($unpaidBalance, 2),
                    'period'     => 'Today',
                ],
            ],
        ]);
    }

    /**
     * Return real recent activity feed.
     */
    public function recentActivity(): JsonResponse
    {
        $activities = collect();

        // Recent check-ins
        $recentCheckins = Reservation::with(['guest', 'room'])
            ->where('status', 'checked_in')
            ->orderBy('updated_at', 'desc')
            ->limit(5)
            ->get();

        foreach ($recentCheckins as $reservation) {
            $activities->push([
                'id'          => $reservation->id,
                'type'        => 'check_in',
                'guest'       => $reservation->guest->first_name . ' ' . $reservation->guest->last_name,
                'room'        => $reservation->room->room_number ?? 'N/A',
                'description' => 'Checked in',
                'time'        => $reservation->updated_at->diffForHumans(),
            ]);
        }

        // Recent check-outs
        $recentCheckouts = Reservation::with(['guest', 'room'])
            ->where('status', 'checked_out')
            ->orderBy('updated_at', 'desc')
            ->limit(5)
            ->get();

        foreach ($recentCheckouts as $reservation) {
            $activities->push([
                'id'          => $reservation->id,
                'type'        => 'check_out',
                'guest'       => $reservation->guest->first_name . ' ' . $reservation->guest->last_name,
                'room'        => $reservation->room->room_number ?? 'N/A',
                'description' => 'Checked out',
                'time'        => $reservation->updated_at->diffForHumans(),
            ]);
        }

        // Recent reservations
        $recentReservations = Reservation::with(['guest', 'room'])
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        foreach ($recentReservations as $reservation) {
            $activities->push([
                'id'          => $reservation->id,
                'type'        => 'reservation',
                'guest'       => $reservation->guest->first_name . ' ' . $reservation->guest->last_name,
                'room'        => $reservation->room->room_number ?? 'N/A',
                'description' => 'New reservation',
                'time'        => $reservation->created_at->diffForHumans(),
            ]);
        }

        // Sort by time and limit to 10
        $activities = $activities->sortByDesc(function ($item) {
            return strtotime($item['time']);
        })->take(10)->values();

        return response()->json([
            'data' => $activities,
        ]);
    }

    /**
     * Return revenue trend data (last 7 days).
     */
    public function occupancyTrend(): JsonResponse
    {
        $data = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i)->startOfDay();

            $revenue = Payment::whereDate('created_at', $date)
                ->where('status', 'completed')
                ->sum('amount');

            $unpaidBalance = Folio::whereDate('created_at', $date)
                ->where('status', 'open')
                ->withSum('charges as total_charges', 'amount')
                ->withSum('payments as total_payments', 'amount')
                ->get()
                ->sum(function ($folio) {
                    $charges = $folio->total_charges ?? 0;
                    $payments = $folio->total_payments ?? 0;
                    return max(0, $charges - $payments);
                });

            $totalRevenue = $revenue + $unpaidBalance;

            $data[] = [
                'date' => $date->format('D'),
                'revenue' => round($totalRevenue, 2),
            ];
        }

        return response()->json([
            'data' => $data,
        ]);
    }
}
