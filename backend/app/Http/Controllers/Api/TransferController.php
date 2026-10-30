<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transfer;
use App\Services\TransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransferController extends Controller
{
    public function __construct(private readonly TransferService $transferService) {}

    public function index(): JsonResponse
    {
        $transfers = Transfer::with([
            'items.product',
            'items.batch',
            'originWarehouse',
            'destinationWarehouse',
            'requester',
            'approver',
        ])->orderByDesc('id')->get();

        return response()->json(['data' => $transfers]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'origin_warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'destination_warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'submit_immediately' => ['nullable', 'boolean'],
            'observations' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.batch_id' => ['required', 'integer', 'exists:batches,id'],
            'items.*.requested_qty' => ['required', 'integer', 'min:1'],
        ]);

        $transfer = $this->transferService->create($validated, $request->user());

        return response()->json([
            'message' => 'Traslado creado correctamente.',
            'data' => $transfer,
        ], 201);
    }

    public function submit(Request $request, Transfer $transfer): JsonResponse
    {
        return response()->json([
            'message' => 'Traslado enviado a estado SOLICITADO.',
            'data' => $this->transferService->submit($transfer, $request->user()),
        ]);
    }

    public function approve(Request $request, Transfer $transfer): JsonResponse
    {
        return response()->json([
            'message' => 'Traslado APROBADO cumpliendo segregación de funciones (RN-08).',
            'data' => $this->transferService->approve($transfer, $request->user()),
        ]);
    }

    public function dispatchTransfer(Request $request, Transfer $transfer): JsonResponse
    {
        return response()->json([
            'message' => 'Traslado despachado (EN_TRANSITO) y descontado de bodega origen.',
            'data' => $this->transferService->dispatch($transfer, $request->user()),
        ]);
    }

    public function receive(Request $request, Transfer $transfer): JsonResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.transfer_item_id' => ['required', 'integer', 'exists:transfer_items,id'],
            'items.*.received_qty' => ['required', 'integer', 'min:0'],
            'items.*.discrepancy_reason' => ['nullable', 'string'],
        ]);

        return response()->json([
            'message' => 'Recepción de traslado procesada en bodega destino.',
            'data' => $this->transferService->receive($transfer, $validated['items'], $request->user()),
        ]);
    }

    public function cancel(Request $request, Transfer $transfer): JsonResponse
    {
        return response()->json([
            'message' => 'Traslado ANULADO.',
            'data' => $this->transferService->cancel($transfer, $request->user()),
        ]);
    }
}
