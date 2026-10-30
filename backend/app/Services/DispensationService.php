<?php

namespace App\Services;

use App\Models\Dispensation;
use App\Models\DispensationItem;
use App\Models\Inventory;
use App\Models\Prescription;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DispensationService
{
    public function __construct(private readonly KardexService $kardexService) {}

    /**
     * Previsualiza qué lotes asignará el algoritmo FEFO antes de confirmar en el Frontend (Parte B).
     */
    public function previewFefoAllocation(int $warehouseId, int $productId, int $requestedQty): array
    {
        $inventories = Inventory::query()
            ->join('batches', 'inventories.batch_id', '=', 'batches.id')
            ->with(['batch', 'product'])
            ->where('inventories.warehouse_id', $warehouseId)
            ->where('inventories.product_id', $productId)
            ->where('inventories.quantity', '>', 0)
            ->whereDate('batches.expiration_date', '>', now()->toDateString()) // RN-01: Excluir vencidos
            ->orderBy('batches.expiration_date', 'asc') // RN-02: FEFO
            ->orderBy('batches.id', 'asc')
            ->select('inventories.*')
            ->get();

        $remaining = $requestedQty;
        $allocations = [];

        foreach ($inventories as $inv) {
            if ($remaining <= 0) {
                break;
            }
            $take = min($inv->quantity, $remaining);
            $allocations[] = [
                'batch_id' => $inv->batch_id,
                'batch_number' => $inv->batch->batch_number,
                'expiration_date' => $inv->batch->expiration_date->toDateString(),
                'available_qty' => $inv->quantity,
                'allocated_qty' => $take,
            ];
            $remaining -= $take;
        }

        return [
            'product_id' => $productId,
            'requested_qty' => $requestedQty,
            'fulfilled' => $remaining === 0,
            'missing_qty' => $remaining,
            'allocations' => $allocations,
        ];
    }

    /**
     * Ejecuta una dispensación transaccional cumpliendo RN-01, RN-02, RN-03, RN-04, RN-05, RN-06 y RN-09.
     */
    public function dispense(array $payload, User $actor): Dispensation
    {
        if (! in_array($actor->role, ['auxiliar_farmacia', 'regente_farmacia'], true)) {
            throw new DomainException('No tiene permisos para dispensar medicamentos.');
        }

        // RN-09: Idempotencia - Si se reintenta la misma llave, retornar la dispensación existente
        $existing = Dispensation::with(['items.product', 'items.batch', 'patient', 'warehouse'])
            ->where('idempotency_key', $payload['idempotency_key'])
            ->first();

        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($payload, $actor) {
            // Doble verificación de idempotencia dentro de la transacción
            $existingInTx = Dispensation::with(['items.product', 'items.batch', 'patient', 'warehouse'])
                ->where('idempotency_key', $payload['idempotency_key'])
                ->first();

            if ($existingInTx) {
                return $existingInTx;
            }

            // RN-04: Bloquear la prescripción para evitar sobre-dispensación concurrente
            $prescription = Prescription::with('items.product')
                ->lockForUpdate()
                ->findOrFail($payload['prescription_id']);

            if ($prescription->status !== 'ACTIVE' || $prescription->valid_until->startOfDay()->lt(now()->startOfDay())) {
                throw new DomainException('RN-04: La prescripción no está vigente o ya fue completada.');
            }

            $authorizerId = null;

            // Verificar si algún ítem a dispensar es de control especial (RN-05)
            foreach ($payload['items'] as $itemReq) {
                $prescItem = $prescription->items->firstWhere('product_id', $itemReq['product_id']);
                if (! $prescItem) {
                    throw new DomainException("El producto ID {$itemReq['product_id']} no pertenece a esta prescripción.");
                }

                if ($prescItem->product->is_special_control) {
                    $authorizerId = $this->verifySpecialControlAuthorization($actor, $payload);
                }
            }

            $dispensation = Dispensation::create([
                'idempotency_key' => $payload['idempotency_key'],
                'prescription_id' => $prescription->id,
                'patient_id' => $prescription->patient_id,
                'warehouse_id' => $payload['warehouse_id'],
                'dispensed_by' => $actor->id,
                'authorized_by' => $authorizerId,
            ]);

            foreach ($payload['items'] as $itemReq) {
                $qtyToDispense = (int) $itemReq['quantity'];
                if ($qtyToDispense <= 0) {
                    throw new DomainException('La cantidad a dispensar debe ser mayor a cero.');
                }

                $prescItem = $prescription->items->firstWhere('product_id', $itemReq['product_id']);
                $pendingPrescribed = $prescItem->prescribed_quantity - $prescItem->dispensed_quantity;

                // RN-04: No se puede dispensar más de lo prescrito
                if ($qtyToDispense > $pendingPrescribed) {
                    throw new DomainException(
                        "RN-04: No se puede dispensar {$qtyToDispense} de {$prescItem->product->name}. Saldo pendiente en prescripción: {$pendingPrescribed}."
                    );
                }

                // RN-01, RN-02, RN-03: Seleccionar lotes vigentes en orden FEFO con bloqueo de fila (SELECT ... FOR UPDATE)
                $availableInventories = Inventory::query()
                    ->join('batches', 'inventories.batch_id', '=', 'batches.id')
                    ->where('inventories.warehouse_id', $payload['warehouse_id'])
                    ->where('inventories.product_id', $itemReq['product_id'])
                    ->where('inventories.quantity', '>', 0)
                    ->whereDate('batches.expiration_date', '>', now()->toDateString()) // RN-01
                    ->orderBy('batches.expiration_date', 'asc') // RN-02: FEFO
                    ->orderBy('batches.id', 'asc')
                    ->select('inventories.*')
                    ->lockForUpdate() // RN-03: Seguridad ante concurrencia
                    ->get();

                $remaining = $qtyToDispense;

                foreach ($availableInventories as $inventory) {
                    if ($remaining <= 0) {
                        break;
                    }

                    $take = min($inventory->quantity, $remaining);
                    $newBalance = $inventory->quantity - $take;

                    $inventory->update(['quantity' => $newBalance]);
                    $remaining -= $take;

                    DispensationItem::create([
                        'dispensation_id' => $dispensation->id,
                        'product_id' => $inventory->product_id,
                        'batch_id' => $inventory->batch_id,
                        'quantity' => $take,
                    ]);

                    // RN-06: Registrar salida inmutable en Kardex
                    $this->kardexService->recordMovement(
                        warehouseId: $payload['warehouse_id'],
                        productId: $inventory->product_id,
                        batchId: $inventory->batch_id,
                        type: 'SALIDA_DISPENSACION',
                        quantity: -$take,
                        balanceAfter: $newBalance,
                        userId: $actor->id,
                        reference: $dispensation,
                        reason: "Dispensación prescripción #{$prescription->id}"
                    );
                }

                if ($remaining > 0) {
                    throw new DomainException(
                        "RN-03: Stock vigente insuficiente para {$prescItem->product->name} (faltan {$remaining} unidades en lotes no vencidos)."
                    );
                }

                $prescItem->increment('dispensed_quantity', $qtyToDispense);
            }

            // Actualizar estado de la prescripción si todos los ítems fueron dispensados en su totalidad
            $prescription->refresh();
            $allCompleted = $prescription->items->every(
                fn ($i) => $i->dispensed_quantity >= $i->prescribed_quantity
            );
            if ($allCompleted) {
                $prescription->update(['status' => 'COMPLETED']);
            }

            return $dispensation->load(['items.product', 'items.batch', 'patient', 'warehouse']);
        });
    }

    /**
     * RN-05: Medicamentos de control especial requieren autorización de un SEGUNDO usuario con rol regente_farmacia.
     */
    private function verifySpecialControlAuthorization(User $actor, array $payload): int
    {
        $authorizerEmail = $payload['authorizer_email'] ?? null;
        $authorizerPassword = $payload['authorizer_password'] ?? null;
        $authorizerId = $payload['authorized_by'] ?? null;

        $authorizer = null;
        if ($authorizerEmail) {
            $authorizer = User::where('email', $authorizerEmail)->first();
            if (! $authorizer || ($authorizerPassword && ! Hash::check($authorizerPassword, $authorizer->password))) {
                throw new DomainException('RN-05: Credenciales del segundo usuario autorizador inválidas.');
            }
        } elseif ($authorizerId) {
            $authorizer = User::find($authorizerId);
        }

        if (! $authorizer) {
            throw new DomainException('RN-05: Los medicamentos de control especial requieren la autorización de un regente de farmacia.');
        }

        if ($authorizer->role !== 'regente_farmacia') {
            throw new DomainException('RN-05: El usuario autorizador debe tener el rol regente_farmacia.');
        }

        if ($authorizer->id === $actor->id) {
            throw new DomainException('RN-05: La autorización de control especial debe ser realizada por un SEGUNDO usuario distinto a quien dispensa.');
        }

        return $authorizer->id;
    }
}
