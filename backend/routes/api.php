<?php

use App\Http\Controllers\Api\AiAssistantController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DispensationController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\PrescriptionController;
use App\Http\Controllers\Api\TransferController;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// Endpoint de salud para Docker Compose y monitoreo (Sección 5)
Route::get('/health', function () {
    DB::connection()->getPdo();

    return response()->json([
        'status' => 'ok',
        'service' => 'fartmar-ips-api',
        'timestamp' => now()->toIso8601String(),
    ]);
});

// Autenticación pública
Route::post('/login', [AuthController::class, 'login']);

// Rutas protegidas por token de Laravel Sanctum
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Catálogos, Inventario por Lote, Alertas (RN-11), Kardex y Ajustes (RN-06)
    Route::get('/warehouses', [InventoryController::class, 'warehouses']);
    Route::get('/products', [InventoryController::class, 'products']);
    Route::get('/inventory', [InventoryController::class, 'index']);
    Route::get('/inventory/alerts', [InventoryController::class, 'alerts']);
    Route::post('/inventory/adjust', [InventoryController::class, 'adjust']);
    Route::get('/kardex', [InventoryController::class, 'kardex']);

    // Prescripciones y Pacientes con enmascaramiento para Auditor (RN-10)
    Route::get('/prescriptions', [PrescriptionController::class, 'index']);

    // Dispensación FEFO e Idempotencia (RN-01 a RN-05, RN-09)
    Route::get('/dispensations', [DispensationController::class, 'index']);
    Route::post('/dispensations/preview-fefo', [DispensationController::class, 'previewFefo']);
    Route::post('/dispensations', [DispensationController::class, 'store']);

    // Traslados entre Bodegas (RN-07 y RN-08)
    Route::get('/transfers', [TransferController::class, 'index']);
    Route::post('/transfers', [TransferController::class, 'store']);
    Route::post('/transfers/{transfer}/submit', [TransferController::class, 'submit']);
    Route::post('/transfers/{transfer}/approve', [TransferController::class, 'approve']);
    Route::post('/transfers/{transfer}/dispatch', [TransferController::class, 'dispatchTransfer']);
    Route::post('/transfers/{transfer}/receive', [TransferController::class, 'receive']);
    Route::post('/transfers/{transfer}/cancel', [TransferController::class, 'cancel']);
    Route::post('/ai/ask', [AiAssistantController::class, 'ask']);

    // Bitácora de Auditoría de Accesos (RN-10)
    Route::get('/audit-logs', function (Request $request) {
        if (! in_array($request->user()->role, ['auditor', 'admin', 'regente_farmacia'], true)) {
            return response()->json(['message' => 'No autorizado para ver bitácora de auditoría.'], 403);
        }

        return response()->json([
            'data' => AuditLog::with('user:id,name,email,role')->orderByDesc('id')->limit(100)->get(),
        ]);
    });
});
