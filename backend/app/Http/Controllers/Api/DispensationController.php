<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PatientResource;
use App\Models\AuditLog;
use App\Models\Dispensation;
use App\Services\DispensationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DispensationController extends Controller
{
    public function __construct(private readonly DispensationService $dispensationService) {}

    public function index(Request $request): JsonResponse
    {
        $dispensations = Dispensation::with(['items.product', 'items.batch', 'patient', 'warehouse', 'dispenser', 'authorizer'])
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (Dispensation $d) => [
                'id'              => $d->id,
                'idempotency_key' => $d->idempotency_key,
                'prescription_id' => $d->prescription_id,
                'warehouse_name'  => $d->warehouse->name,
                'dispensed_by'    => $d->dispenser->name,
                'authorized_by'   => $d->authorizer?->name,
                'created_at'      => $d->created_at?->toIso8601String(),
                'patient'         => (new PatientResource($d->patient))->toArray($request),
                'items'           => $d->items->map(fn ($i) => [
                    'product_name'    => $i->product->name,
                    'batch_number'    => $i->batch->batch_number,
                    'expiration_date' => $i->batch->expiration_date->toDateString(),
                    'quantity'        => $i->quantity,
                ]),
            ]);

        return response()->json(['data' => $dispensations]);
    }

    public function previewFefo(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id'  => ['required', 'integer', 'exists:warehouses,id'],
            'product_id'    => ['required', 'integer', 'exists:products,id'],
            'requested_qty' => ['required', 'integer', 'min:1'],
        ]);

        $preview = $this->dispensationService->previewFefoAllocation(
            warehouseId: (int) $validated['warehouse_id'],
            productId: (int) $validated['product_id'],
            requestedQty: (int) $validated['requested_qty']
        );

        return response()->json(['data' => $preview]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'idempotency_key'     => ['required', 'string', 'max:100'],
            'prescription_id'     => ['required', 'integer', 'exists:prescriptions,id'],
            'warehouse_id'        => ['required', 'integer', 'exists:warehouses,id'],
            'authorizer_email'    => ['nullable', 'email'],
            'authorizer_password' => ['nullable', 'string'],
            'authorized_by'       => ['nullable', 'integer'],
            'items'               => ['required', 'array', 'min:1'],
            'items.*.product_id'  => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity'    => ['required', 'integer', 'min:1'],
        ]);

        $dispensation = $this->dispensationService->dispense($validated, $request->user());

        AuditLog::create([
            'user_id'        => $request->user()->id,
            'patient_id'     => $dispensation->patient_id,
            'action'         => 'DISPENSE_MEDICATION',
            'resource_type'  => 'Dispensation',
            'resource_id'    => $dispensation->id,
            'correlation_id' => $request->attributes->get('correlation_id'),
            'ip_address'     => $request->ip(),
        ]);

        return response()->json([
            'message' => 'Dispensación procesada exitosamente bajo FEFO.',
            'data'    => $dispensation,
        ], 201);
    }
}