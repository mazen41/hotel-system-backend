<?php

namespace App\Http\Controllers\Api\Service;

use App\Http\Controllers\Controller;
use App\Models\Charge;
use App\Models\Folio;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ServiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Service::with(['guest', 'reservation', 'createdBy']);

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('guest_id')) {
            $query->where('guest_id', $request->guest_id);
        }

        if ($request->filled('reservation_id')) {
            $query->where('reservation_id', $request->reservation_id);
        }

        $services = $query->orderBy('created_at', 'desc')
            ->paginate((int) $request->get('per_page', 15))
            ->withQueryString();

        return response()->json([
            'data' => $services->items(),
            'meta' => [
                'current_page' => $services->currentPage(),
                'from' => $services->firstItem(),
                'last_page' => $services->lastPage(),
                'per_page' => $services->perPage(),
                'to' => $services->lastItem(),
                'total' => $services->total(),
            ],
            'links' => [
                'first' => $services->url(1),
                'last' => $services->url($services->lastPage()),
                'prev' => $services->previousPageUrl(),
                'next' => $services->nextPageUrl(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => 'required|in:trip,service',
            'guest_id' => 'required|exists:guests,id',
            'reservation_id' => 'required|exists:reservations,id',
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'fees' => 'nullable|numeric|min:0',
            'invoice_image' => 'nullable|image|max:5120', // Max 5MB
        ]);

        return DB::transaction(function () use ($validated, $request) {
            // Handle file upload
            $invoiceImagePath = null;
            if ($request->hasFile('invoice_image')) {
                $invoiceImagePath = $request->file('invoice_image')->store('invoices', 'public');
            }

            $service = Service::create([
                'type' => $validated['type'],
                'guest_id' => $validated['guest_id'],
                'reservation_id' => $validated['reservation_id'],
                'name' => $validated['name'] ?? null,
                'description' => $validated['description'] ?? null,
                'fees' => (float) ($validated['fees'] ?? 0),
                'invoice_image' => $invoiceImagePath,
                'created_by' => auth()->id(),
            ]);

            // Add to folio if fees > 0
            if ($service->fees > 0) {
                $folio = Folio::where('reservation_id', $service->reservation_id)
                    ->where('status', 'open')
                    ->first();

                if (!$folio) {
                    $folio = Folio::create([
                        'reservation_id' => $service->reservation_id,
                        'guest_id' => $service->guest_id,
                        'status' => 'open',
                        'created_by' => auth()->id(),
                    ]);
                }

                Charge::create([
                    'folio_id' => $folio->id,
                    'reservation_id' => $service->reservation_id,
                    'charge_type' => 'service',
                    'description' => ucfirst($service->type) . ': ' . ($service->name ?? 'N/A'),
                    'amount' => $service->fees,
                    'created_by' => auth()->id(),
                ]);

                $folio->updateTotals();
            }

            return response()->json([
                'message' => ucfirst($service->type) . ' created successfully.',
                'data' => $service->load(['guest', 'reservation', 'createdBy']),
            ], 201);
        });
    }

    public function show(Service $service): JsonResponse
    {
        $service->load(['guest', 'reservation', 'createdBy']);

        return response()->json([
            'data' => $service,
        ]);
    }

    public function update(Request $request, Service $service): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'fees' => 'nullable|numeric|min:0',
            'invoice_image' => 'nullable|image|max:5120',
        ]);

        return DB::transaction(function () use ($validated, $request, $service) {
            $oldFees = $service->fees;

            // Handle file upload
            if ($request->hasFile('invoice_image')) {
                // Delete old image
                if ($service->invoice_image) {
                    Storage::disk('public')->delete($service->invoice_image);
                }
                $invoiceImagePath = $request->file('invoice_image')->store('invoices', 'public');
                $service->invoice_image = $invoiceImagePath;
            }

            $service->update([
                'name' => $validated['name'] ?? $service->name,
                'description' => $validated['description'] ?? $service->description,
                'fees' => (float) ($validated['fees'] ?? $service->fees),
            ]);

            // Update folio charge if fees changed
            if ($oldFees != $service->fees) {
                $folio = Folio::where('reservation_id', $service->reservation_id)
                    ->where('status', 'open')
                    ->first();

                if ($folio) {
                    $charge = Charge::where('reservation_id', $service->reservation_id)
                        ->where('charge_type', 'service')
                        ->where('description', 'like', ucfirst($service->type) . ': ' . ($service->name ?? 'N/A'))
                        ->first();

                    if ($charge) {
                        $charge->update(['amount' => $service->fees]);
                        $folio->updateTotals();
                    }
                }
            }

            return response()->json([
                'message' => ucfirst($service->type) . ' updated successfully.',
                'data' => $service->fresh()->load(['guest', 'reservation', 'createdBy']),
            ]);
        });
    }

    public function destroy(Service $service): JsonResponse
    {
        return DB::transaction(function () use ($service) {
            // Delete invoice image
            if ($service->invoice_image) {
                Storage::disk('public')->delete($service->invoice_image);
            }

            // Remove from folio charge
            if ($service->fees > 0) {
                $folio = Folio::where('reservation_id', $service->reservation_id)
                    ->where('status', 'open')
                    ->first();

                if ($folio) {
                    $charge = Charge::where('reservation_id', $service->reservation_id)
                        ->where('charge_type', 'service')
                        ->where('description', 'like', ucfirst($service->type) . ': ' . ($service->name ?? 'N/A'))
                        ->first();

                    if ($charge) {
                        $charge->delete();
                        $folio->updateTotals();
                    }
                }
            }

            $service->delete();

            return response()->json([
                'message' => ucfirst($service->type) . ' deleted successfully.',
            ]);
        });
    }
}
