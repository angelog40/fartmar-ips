<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\WarehouseProductSetting;
use Illuminate\Support\Facades\DB;

class InventoryAlertService
{
    /**
     * RN-11: Alertas de lotes vencidos o que vencen en <= 90 días y productos bajo stock mínimo por bodega.
     */
    public function getAlerts(?int $warehouseId = null, int $daysThreshold = 90): array
    {
        // 1. Lotes con existencias > 0 que ya vencieron o vencen en <= $daysThreshold días
        $expiringQuery = Inventory::query()
            ->join('batches', 'inventories.batch_id', '=', 'batches.id')
            ->with(['warehouse', 'product', 'batch'])
            ->where('inventories.quantity', '>', 0)
            ->whereDate('batches.expiration_date', '<=', now()->addDays($daysThreshold)->toDateString())
            ->orderBy('batches.expiration_date', 'asc')
            ->select('inventories.*');

        if ($warehouseId) {
            $expiringQuery->where('inventories.warehouse_id', $warehouseId);
        }

        $expiringBatches = $expiringQuery->get()->map(function (Inventory $inv) {
            $daysLeft = (int) now()->startOfDay()->diffInDays($inv->batch->expiration_date->startOfDay(), false);

            return [
                'inventory_id' => $inv->id,
                'warehouse_id' => $inv->warehouse_id,
                'warehouse_name' => $inv->warehouse->name,
                'product_id' => $inv->product_id,
                'product_name' => $inv->product->name,
                'batch_id' => $inv->batch_id,
                'batch_number' => $inv->batch->batch_number,
                'expiration_date' => $inv->batch->expiration_date->toDateString(),
                'days_remaining' => $daysLeft,
                'status' => $daysLeft <= 0 ? 'VENCIDO' : ($daysLeft <= 30 ? 'CRITICO_30D' : 'ALERTA_90D'),
                'quantity' => $inv->quantity,
            ];
        });

        // 2. Productos con stock vigente (no vencido) por debajo del stock mínimo definido por bodega
        $settingsQuery = WarehouseProductSetting::query()
            ->join('warehouses', 'warehouse_product_settings.warehouse_id', '=', 'warehouses.id')
            ->join('products', 'warehouse_product_settings.product_id', '=', 'products.id')
            ->select([
                'warehouse_product_settings.warehouse_id',
                'warehouses.name as warehouse_name',
                'warehouse_product_settings.product_id',
                'products.sku as product_sku',
                'products.name as product_name',
                'warehouse_product_settings.min_stock',
                DB::raw('COALESCE((
                    SELECT SUM(i.quantity)
                    FROM inventories i
                    JOIN batches b ON i.batch_id = b.id
                    WHERE i.warehouse_id = warehouse_product_settings.warehouse_id
                      AND i.product_id = warehouse_product_settings.product_id
                      AND b.expiration_date > CURRENT_DATE
                ), 0) as valid_stock'),
            ]);

        if ($warehouseId) {
            $settingsQuery->where('warehouse_product_settings.warehouse_id', $warehouseId);
        }

        $lowStockProducts = $settingsQuery->get()
            ->filter(fn ($row) => (int) $row->valid_stock < (int) $row->min_stock)
            ->values()
            ->map(fn ($row) => [
                'warehouse_id' => (int) $row->warehouse_id,
                'warehouse_name' => $row->warehouse_name,
                'product_id' => (int) $row->product_id,
                'product_sku' => $row->product_sku,
                'product_name' => $row->product_name,
                'min_stock' => (int) $row->min_stock,
                'valid_stock' => (int) $row->valid_stock,
                'deficit' => (int) $row->min_stock - (int) $row->valid_stock,
            ]);

        return [
            'expiring_batches' => $expiringBatches,
            'low_stock_products' => $lowStockProducts,
        ];
    }
}
