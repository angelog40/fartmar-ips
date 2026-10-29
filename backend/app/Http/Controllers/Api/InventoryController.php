<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\KardexMovement;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\InventoryAlertService;
use App\Services\KardexService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function __construct(
        private readonly InventoryAlertService $alertService,
        private readonly KardexService $kardexService
    ) {}

    public function warehouses(): JsonResponse
    {
        return response()->json(['data' => Warehouse::where('is_active', true)->orderBy('id')->get()]);
    }

    public function products(): JsonResponse
    {
        return response()->json(['data' => Product::with('batches')->orderBy('name')->get()]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Inventory::query()
            ->join('batches', 'inventories.batch_id', '=', 'batches.id')
            ->with(['warehouse', 'product', 'batch'])
            ->orderBy('inventories.warehouse_id')
            ->orderBy('inventories.product_id')
            ->orderBy('batches.expiration_date', 'asc')
            ->select('inventories.*');

        if ($request->filled('warehouse_id')) {
            $query->where('inventories.warehouse_id', (int) $request->query('warehouse_id'));
        }

        if ($request->filled('product_id')) {
            $query->where('inventories.product_id', (int) $request->query('product_id'));
        }

        $items = $query->get()->map(function (Inventory $inv) {
            $daysLeft = (int) now()->startOfDay()->diffInDays($inv->batch->expiration_date->startOfDay(), false);
            return [
                'id'                 => $inv->id,
                'warehouse_id'       => $inv->warehouse_id,
                'warehouse_name'     => $inv->warehouse->name,
                'product_id'         => $inv->product_id,
                'product_sku'        => $inv->product->sku,
                'product_name'       => $inv->product->name,
                'unit'               => $inv->product->unit,
                'is_special_control' => $inv->product->is_special_control,
                'batch_id'           => $inv->batch_id,
                'batch_number'       => $inv->batch->batch_number,
                'expiration_date'    => $inv->batch->expiration_date->toDateString(),
                'days_remaining'     => $daysLeft,
                'expiration_status'  => $daysLeft <= 0 ? 'VENCIDO' : ($daysLeft <= 30 ? 'CRITICO_30D' : ($daysLeft <= 90 ? 'ALERTA_90D' : 'VIGENTE')),
                'quantity'           => $inv->quantity,
            ];
        });

        return response()->json(['data' => $items]);
    }

    public function alerts(Request $request): JsonResponse
    {
        $warehouseId = $request->filled('warehouse_id') ? (int) $request->query('warehouse_id') : null;
        $days = $request->filled('days') ? (int) $request->query('days') : 90;

        return response()->json([
            'data' => $this->alertService->getAlerts($warehouseId, $days),
        ]);
    }

    public function kardex(Request $request): JsonResponse
    {
        $query = KardexMovement::with(['warehouse', 'product', 'batch', 'user'])
            ->orderByDesc('id');

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', (int) $request->query('warehouse_id'));
        }

        if ($request->filled('product_id')) {
            $query->where('product_id', (int) $request->query('product_id'));
        }

        return response()->json(['data' => $query->limit(100)->get()]);
    }

    public function adjust(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id'   => ['required', 'integer', 'exists:warehouses,id'],
            'product_id'     => ['required', 'integer', 'exists:products,id'],
            'batch_id'       => ['required', 'integer', 'exists:batches,id'],
            'quantity_delta' => ['required', 'integer', 'not_in:0'],
            'reason'         => ['required', 'string', 'min:5'],
        ]);

        $inventory = $this->kardexService->adjustInventory(
            warehouseId: (int) $validated['warehouse_id'],
            productId: (int) $validated['product_id'],
            batchId: (int) $validated['batch_id'],
            quantityDelta: (int) $validated['quantity_delta'],
            reason: $validated['reason'],
            actor: $request->user()
        );

        return response()->json([
            'message' => 'Ajuste de inventario aplicado y registrado en Kardex (RN-06).',
            'data'    => $inventory,
        ]);
    }
}