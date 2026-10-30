<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\Inventory;
use App\Models\Transfer;
use App\Models\TransferItem;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TransferService
{
    public function __construct(private readonly KardexService $kardexService) {}

    /**
     * Crea un traslado en estado BORRADOR o SOLICITADO (RN-07).
     */
    public function create(array $payload, User $actor): Transfer
    {
        if (! in_array($actor->role, ['auxiliar_farmacia', 'regente_farmacia'], true)) {
            throw new DomainException('No tiene permisos para crear traslados.');
        }

        if ((int) $payload['origin_warehouse_id'] === (int) $payload['destination_warehouse_id']) {
            throw new DomainException('La bodega de origen y destino deben ser diferentes.');
        }

        $initialStatus = ($payload['submit_immediately'] ?? true) ? 'SOLICITADO' : 'BORRADOR';

        return DB::transaction(function () use ($payload, $actor, $initialStatus) {
            $transfer = Transfer::create([
                'code' => 'TR-'.strtoupper(Str::random(8)),
                'origin_warehouse_id' => $payload['origin_warehouse_id'],
                'destination_warehouse_id' => $payload['destination_warehouse_id'],
                'status' => $initialStatus,
                'requested_by' => $actor->id,
                'observations' => $payload['observations'] ?? null,
            ]);

            foreach ($payload['items'] as $item) {
                $batch = Batch::where('id', $item['batch_id'])
                    ->where('product_id', $item['product_id'])
                    ->firstOrFail();

                // RN-01: Un lote vencido nunca puede trasladarse
                if ($batch->isExpired()) {
                    throw new DomainException("RN-01: El lote {$batch->batch_number} está vencido y no puede trasladarse.");
                }

                TransferItem::create([
                    'transfer_id' => $transfer->id,
                    'product_id' => $item['product_id'],
                    'batch_id' => $item['batch_id'],
                    'requested_qty' => $item['requested_qty'],
                ]);
            }

            return $transfer->load(['items.product', 'items.batch', 'originWarehouse', 'destinationWarehouse', 'requester']);
        });
    }

    /**
     * Pasa un traslado de BORRADOR a SOLICITADO (RN-07).
     */
    public function submit(Transfer $transfer, User $actor): Transfer
    {
        if ($transfer->status !== 'BORRADOR') {
            throw new DomainException("Solo se puede solicitar un traslado en estado BORRADOR (actual: {$transfer->status}).");
        }

        $transfer->update(['status' => 'SOLICITADO']);

        return $transfer->fresh(['items.product', 'items.batch', 'originWarehouse', 'destinationWarehouse']);
    }

    /**
     * Aprueba un traslado (RN-07 y RN-08: Segregación de funciones).
     */
    public function approve(Transfer $transfer, User $actor): Transfer
    {
        if ($actor->role !== 'regente_farmacia') {
            throw new DomainException('Solo un regente de farmacia puede aprobar traslados.');
        }

        if ($transfer->status !== 'SOLICITADO') {
            throw new DomainException("Solo se pueden aprobar traslados en estado SOLICITADO (actual: {$transfer->status}).");
        }

        // RN-08: Quien solicita un traslado no puede aprobarlo
        if ($transfer->requested_by === $actor->id) {
            throw new DomainException('RN-08: Segregación de funciones: quien solicita un traslado no puede aprobarlo.');
        }

        $transfer->update([
            'status' => 'APROBADO',
            'approved_by' => $actor->id,
        ]);

        return $transfer->fresh(['items.product', 'items.batch', 'originWarehouse', 'destinationWarehouse', 'approver']);
    }

    /**
     * Despacha el traslado: el stock sale de la bodega origen y pasa a EN_TRANSITO (RN-01, RN-03, RN-06, RN-07).
     */
    public function dispatch(Transfer $transfer, User $actor): Transfer
    {
        if (! in_array($actor->role, ['auxiliar_farmacia', 'regente_farmacia'], true)) {
            throw new DomainException('No tiene permisos para despachar traslados.');
        }

        return DB::transaction(function () use ($transfer, $actor) {
            $transfer = Transfer::with('items.batch')->lockForUpdate()->findOrFail($transfer->id);

            if ($transfer->status !== 'APROBADO') {
                throw new DomainException("Solo se pueden despachar traslados en estado APROBADO (actual: {$transfer->status}).");
            }

            foreach ($transfer->items as $item) {
                if ($item->batch->isExpired()) {
                    throw new DomainException("RN-01: El lote {$item->batch->batch_number} se encuentra vencido y no puede despacharse.");
                }

                $inventory = Inventory::where('warehouse_id', $transfer->origin_warehouse_id)
                    ->where('product_id', $item->product_id)
                    ->where('batch_id', $item->batch_id)
                    ->lockForUpdate()
                    ->first();

                if (! $inventory || $inventory->quantity < $item->requested_qty) {
                    throw new DomainException("RN-03: Stock insuficiente en bodega origen para el lote {$item->batch->batch_number}.");
                }

                $newBalance = $inventory->quantity - $item->requested_qty;
                $inventory->update(['quantity' => $newBalance]);

                $item->update(['dispatched_qty' => $item->requested_qty]);

                // RN-06: Salida por traslado en Kardex
                $this->kardexService->recordMovement(
                    warehouseId: $transfer->origin_warehouse_id,
                    productId: $item->product_id,
                    batchId: $item->batch_id,
                    type: 'SALIDA_TRASLADO',
                    quantity: -$item->requested_qty,
                    balanceAfter: $newBalance,
                    userId: $actor->id,
                    reference: $transfer,
                    reason: "Despacho traslado {$transfer->code} hacia bodega ID {$transfer->destination_warehouse_id}"
                );
            }

            $transfer->update([
                'status' => 'EN_TRANSITO',
                'dispatched_by' => $actor->id,
            ]);

            return $transfer->fresh(['items.product', 'items.batch', 'originWarehouse', 'destinationWarehouse']);
        });
    }

    /**
     * Recibe el traslado en la bodega destino: entra el stock recibido y registra discrepancias (RN-06, RN-07).
     */
    public function receive(Transfer $transfer, array $receivedItemsPayload, User $actor): Transfer
    {
        if (! in_array($actor->role, ['auxiliar_farmacia', 'regente_farmacia'], true)) {
            throw new DomainException('No tiene permisos para recibir traslados.');
        }

        return DB::transaction(function () use ($transfer, $receivedItemsPayload, $actor) {
            $transfer = Transfer::with('items.batch')->lockForUpdate()->findOrFail($transfer->id);

            if ($transfer->status !== 'EN_TRANSITO') {
                throw new DomainException("Solo se pueden recibir traslados en estado EN_TRANSITO (actual: {$transfer->status}).");
            }

            $hasDiscrepancy = false;
            $receivedMap = collect($receivedItemsPayload)->keyBy('transfer_item_id');

            foreach ($transfer->items as $item) {
                $input = $receivedMap->get($item->id);
                $receivedQty = $input ? (int) $input['received_qty'] : $item->dispatched_qty;
                $discrepancyReason = $input['discrepancy_reason'] ?? null;

                if ($receivedQty < 0 || $receivedQty > $item->dispatched_qty) {
                    throw new DomainException("La cantidad recibida ({$receivedQty}) debe estar entre 0 y la despachada ({$item->dispatched_qty}).");
                }

                $discrepancyQty = $item->dispatched_qty - $receivedQty;
                if ($discrepancyQty > 0) {
                    $hasDiscrepancy = true;
                }

                $item->update([
                    'received_qty' => $receivedQty,
                    'discrepancy_qty' => $discrepancyQty,
                    'discrepancy_reason' => $discrepancyQty > 0 ? ($discrepancyReason ?? 'Faltante en recepción pendiente de resolución') : null,
                ]);

                if ($receivedQty > 0) {
                    $destInventory = Inventory::where('warehouse_id', $transfer->destination_warehouse_id)
                        ->where('product_id', $item->product_id)
                        ->where('batch_id', $item->batch_id)
                        ->lockForUpdate()
                        ->first();

                    if (! $destInventory) {
                        $destInventory = Inventory::create([
                            'warehouse_id' => $transfer->destination_warehouse_id,
                            'product_id' => $item->product_id,
                            'batch_id' => $item->batch_id,
                            'quantity' => 0,
                        ]);
                    }

                    $newBalance = $destInventory->quantity + $receivedQty;
                    $destInventory->update(['quantity' => $newBalance]);

                    // RN-06: Entrada por traslado en Kardex
                    $this->kardexService->recordMovement(
                        warehouseId: $transfer->destination_warehouse_id,
                        productId: $item->product_id,
                        batchId: $item->batch_id,
                        type: 'ENTRADA_TRASLADO',
                        quantity: $receivedQty,
                        balanceAfter: $newBalance,
                        userId: $actor->id,
                        reference: $transfer,
                        reason: "Recepción traslado {$transfer->code} desde bodega ID {$transfer->origin_warehouse_id}"
                    );
                }
            }

            $transfer->update([
                'status' => $hasDiscrepancy ? 'RECIBIDO_PARCIAL' : 'RECIBIDO',
                'received_by' => $actor->id,
            ]);

            return $transfer->fresh(['items.product', 'items.batch', 'originWarehouse', 'destinationWarehouse']);
        });
    }

    /**
     * Anula un traslado antes de que haya salido de la bodega origen (RN-07).
     */
    public function cancel(Transfer $transfer, User $actor): Transfer
    {
        if (! in_array($transfer->status, ['BORRADOR', 'SOLICITADO', 'APROBADO'], true)) {
            throw new DomainException("No se puede anular un traslado en estado {$transfer->status} porque ya afectó existencias.");
        }

        $transfer->update(['status' => 'ANULADO']);

        return $transfer->fresh(['items.product', 'items.batch', 'originWarehouse', 'destinationWarehouse']);
    }
}
