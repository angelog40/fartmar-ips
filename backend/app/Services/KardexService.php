<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\Inventory;
use App\Models\KardexMovement;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class KardexService
{
    /**
     * Registra un movimiento inmutable en el kardex (RN-06).
     */
    public function recordMovement(
        int $warehouseId,
        int $productId,
        int $batchId,
        string $type,
        int $quantity,
        int $balanceAfter,
        int $userId,
        ?Model $reference = null,
        ?string $reason = null
    ): KardexMovement {
        return KardexMovement::create([
            'warehouse_id'   => $warehouseId,
            'product_id'     => $productId,
            'batch_id'       => $batchId,
            'type'           => $type,
            'quantity'       => $quantity,
            'balance_after'  => $balanceAfter,
            'reference_type' => $reference ? class_basename($reference) : null,
            'reference_id'   => $reference?->getKey(),
            'reason'         => $reason,
            'user_id'        => $userId,
        ]);
    }

    /**
     * Ajuste manual de inventario con motivo obligatorio (Sección 3 y RN-06).
     * Exclusivo para regente_farmacia.
     */
    public function adjustInventory(
        int $warehouseId,
        int $productId,
        int $batchId,
        int $quantityDelta,
        string $reason,
        User $actor
    ): Inventory {
        if ($actor->role !== 'regente_farmacia') {
            throw new DomainException('Solo un regente de farmacia puede realizar ajustes de inventario.');
        }

        if ($quantityDelta === 0) {
            throw new DomainException('La cantidad del ajuste debe ser distinta de cero.');
        }

        if (trim($reason) === '') {
            throw new DomainException('Todo ajuste de inventario requiere un motivo (RN-06).');
        }

        return DB::transaction(function () use ($warehouseId, $productId, $batchId, $quantityDelta, $reason, $actor) {
            Batch::where('id', $batchId)->where('product_id', $productId)->firstOrFail();

            $inventory = Inventory::where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
                ->where('batch_id', $batchId)
                ->lockForUpdate()
                ->first();

            if (!$inventory) {
                $inventory = Inventory::create([
                    'warehouse_id' => $warehouseId,
                    'product_id'   => $productId,
                    'batch_id'     => $batchId,
                    'quantity'     => 0,
                ]);
            }

            $newBalance = $inventory->quantity + $quantityDelta;
            if ($newBalance < 0) {
                throw new DomainException('RN-03: El stock no puede quedar negativo tras el ajuste.');
            }

            $inventory->update(['quantity' => $newBalance]);

            $this->recordMovement(
                warehouseId: $warehouseId,
                productId: $productId,
                batchId: $batchId,
                type: 'AJUSTE',
                quantity: $quantityDelta,
                balanceAfter: $newBalance,
                userId: $actor->id,
                reason: $reason
            );

            return $inventory->load(['warehouse', 'product', 'batch']);
        });
    }
}