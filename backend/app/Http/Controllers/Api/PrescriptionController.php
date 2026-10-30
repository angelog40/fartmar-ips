<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PatientResource;
use App\Models\AuditLog;
use App\Models\Prescription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PrescriptionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Prescription::with(['patient', 'doctor', 'items.product'])
            ->orderByDesc('id');

        if ($request->filled('search')) {
            $search = trim((string) $request->query('search'));
            $query->whereHas('patient', function ($q) use ($search) {
                $q->where('document_number', 'ILIKE', "%{$search}%")
                    ->orWhere('full_name', 'ILIKE', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $prescriptions = $query->get();

        // RN-10: Registrar en bitácora cada acceso a datos de pacientes
        $patientIds = $prescriptions->pluck('patient_id')->unique();
        foreach ($patientIds as $patientId) {
            AuditLog::create([
                'user_id' => $request->user()?->id,
                'patient_id' => $patientId,
                'action' => 'VIEW_PATIENT_PRESCRIPTION',
                'resource_type' => 'Prescription',
                'correlation_id' => $request->attributes->get('correlation_id'),
                'ip_address' => $request->ip(),
                'metadata' => ['role' => $request->user()?->role],
            ]);
        }

        $data = $prescriptions->map(function (Prescription $p) use ($request) {
            return [
                'id' => $p->id,
                'status' => $p->status,
                'valid_until' => $p->valid_until->toDateString(),
                'notes' => $p->notes,
                'doctor_name' => $p->doctor?->name,
                'patient' => (new PatientResource($p->patient))->toArray($request),
                'items' => $p->items->map(fn ($item) => [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_sku' => $item->product->sku,
                    'product_name' => $item->product->name,
                    'unit' => $item->product->unit,
                    'is_special_control' => $item->product->is_special_control,
                    'prescribed_quantity' => $item->prescribed_quantity,
                    'dispensed_quantity' => $item->dispensed_quantity,
                    'pending_quantity' => $item->prescribed_quantity - $item->dispensed_quantity,
                ]),
            ];
        });

        return response()->json(['data' => $data]);
    }
}
