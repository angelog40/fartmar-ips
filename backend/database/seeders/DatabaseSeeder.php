<?php

namespace Database\Seeders;

use App\Models\Batch;
use App\Models\Inventory;
use App\Models\KardexMovement;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseProductSetting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Usuarios por rol (Sección 3 y 6)
        $users = [
            ['email' => 'auxiliar@fartmar.test', 'name' => 'Carlos Auxiliar',         'role' => 'auxiliar_farmacia'],
            ['email' => 'regente@fartmar.test',  'name' => 'Laura Regente Principal', 'role' => 'regente_farmacia'],
            ['email' => 'regente2@fartmar.test', 'name' => 'Andrés Segundo Regente',  'role' => 'regente_farmacia'],
            ['email' => 'medico@fartmar.test',   'name' => 'Dra. Elena Médico',       'role' => 'medico'],
            ['email' => 'auditor@fartmar.test',  'name' => 'Roberto Auditor',         'role' => 'auditor'],
            ['email' => 'admin@fartmar.test',    'name' => 'Diana Administradora',    'role' => 'admin'],
        ];

        foreach ($users as $u) {
            User::updateOrCreate(
                ['email' => $u['email']],
                [
                    'name'     => $u['name'],
                    'role'     => $u['role'],
                    'password' => Hash::make('password123'),
                ]
            );
        }

        $admin = User::where('role', 'admin')->first();
        $doctor = User::where('role', 'medico')->first();

        // 2. Tres Bodegas (Sección 6)
        $central = Warehouse::updateOrCreate(['code' => 'BOD-CENTRAL'], ['name' => 'Farmacia Central']);
        $urgencias = Warehouse::updateOrCreate(['code' => 'BOD-URGENCIAS'], ['name' => 'Farmacia Urgencias']);
        $hospitalizacion = Warehouse::updateOrCreate(['code' => 'BOD-HOSP'], ['name' => 'Bodega Hospitalización']);

        // 3. Seis Medicamentos, incluyendo 1 de control especial (Sección 6)
        $productsData = [
            ['sku' => 'MED-001', 'name' => 'Acetaminofén 500mg',          'unit' => 'TABLETA', 'is_special_control' => false],
            ['sku' => 'MED-002', 'name' => 'Ibuprofeno 400mg',            'unit' => 'TABLETA', 'is_special_control' => false],
            ['sku' => 'MED-003', 'name' => 'Amoxicilina 500mg',           'unit' => 'CAPSULA', 'is_special_control' => false],
            ['sku' => 'MED-004', 'name' => 'Losartán 50mg',               'unit' => 'TABLETA', 'is_special_control' => false],
            ['sku' => 'MED-005', 'name' => 'Omeprazol 20mg',              'unit' => 'CAPSULA', 'is_special_control' => false],
            ['sku' => 'MED-006', 'name' => 'Morfina Clorhidrato 10mg/mL', 'unit' => 'AMPOLLA', 'is_special_control' => true], // RN-05
        ];

        $products = [];
        foreach ($productsData as $pData) {
            $product = Product::updateOrCreate(['sku' => $pData['sku']], $pData);
            $products[$pData['sku']] = $product;

            foreach ([$central, $urgencias, $hospitalizacion] as $wh) {
                WarehouseProductSetting::updateOrCreate(
                    ['warehouse_id' => $wh->id, 'product_id' => $product->id],
                    ['min_stock' => 25]
                );
            }
        }

        // 4. Lotes (2 o 3 por medicamento: vencidos, <30 días, <90 días y largo plazo)
        $batchesConfig = [
            'MED-001' => [
                ['batch' => 'LOTE-ACE-VENC', 'days' => -15, 'central' => 40,  'urg' => 10, 'hosp' => 0],  // Vencido (RN-01)
                ['batch' => 'LOTE-ACE-20D',  'days' => 20,  'central' => 15,  'urg' => 20, 'hosp' => 10], // Vence en < 30 días (FEFO 1°)
                ['batch' => 'LOTE-ACE-180D', 'days' => 180, 'central' => 100, 'urg' => 50, 'hosp' => 40], // FEFO 2°
            ],
            'MED-002' => [
                ['batch' => 'LOTE-IBU-45D',  'days' => 45,  'central' => 10,  'urg' => 5,  'hosp' => 8],  // Alerta < 90 días y stock bajo (RN-11)
                ['batch' => 'LOTE-IBU-300D', 'days' => 300, 'central' => 8,   'urg' => 10, 'hosp' => 5],
            ],
            'MED-003' => [
                ['batch' => 'LOTE-AMO-25D',  'days' => 25,  'central' => 30,  'urg' => 15, 'hosp' => 20],
                ['batch' => 'LOTE-AMO-240D', 'days' => 240, 'central' => 60,  'urg' => 30, 'hosp' => 25],
            ],
            'MED-004' => [
                ['batch' => 'LOTE-LOS-60D',  'days' => 60,  'central' => 50,  'urg' => 25, 'hosp' => 30],
                ['batch' => 'LOTE-LOS-365D', 'days' => 365, 'central' => 90,  'urg' => 40, 'hosp' => 50],
            ],
            'MED-005' => [
                ['batch' => 'LOTE-OME-120D', 'days' => 120, 'central' => 70,  'urg' => 35, 'hosp' => 20],
                ['batch' => 'LOTE-OME-400D', 'days' => 400, 'central' => 80,  'urg' => 25, 'hosp' => 30],
            ],
            'MED-006' => [
                ['batch' => 'LOTE-MOR-15D',  'days' => 15,  'central' => 12,  'urg' => 8,  'hosp' => 5],  // Control especial < 30 días
                ['batch' => 'LOTE-MOR-200D', 'days' => 200, 'central' => 30,  'urg' => 15, 'hosp' => 10],
            ],
        ];

        foreach ($batchesConfig as $sku => $batchesList) {
            $product = $products[$sku];
            foreach ($batchesList as $b) {
                $batch = Batch::updateOrCreate(
                    ['product_id' => $product->id, 'batch_number' => $b['batch']],
                    ['expiration_date' => now()->addDays($b['days'])->toDateString()]
                );

                $allocations = [
                    [$central, $b['central']],
                    [$urgencias, $b['urg']],
                    [$hospitalizacion, $b['hosp']],
                ];

                foreach ($allocations as [$wh, $qty]) {
                    Inventory::firstOrCreate(
                        ['warehouse_id' => $wh->id, 'product_id' => $product->id, 'batch_id' => $batch->id],
                        ['quantity' => $qty]
                    );

                    if ($qty > 0 && !KardexMovement::where('warehouse_id', $wh->id)->where('batch_id', $batch->id)->exists()) {
                        KardexMovement::create([
                            'warehouse_id'   => $wh->id,
                            'product_id'     => $product->id,
                            'batch_id'       => $batch->id,
                            'type'           => 'ENTRADA',
                            'quantity'       => $qty,
                            'balance_after'  => $qty,
                            'reference_type' => 'SEED_INIT',
                            'reference_id'   => 1,
                            'reason'         => 'Inventario inicial de datos semilla',
                            'user_id'        => $admin->id,
                        ]);
                    }
                }
            }
        }

        // 5. Tres Pacientes Sintéticos con Prescripciones Vigentes (Sección 6)
        $p1 = Patient::updateOrCreate(
            ['document_number' => '1000123456'],
            ['document_type' => 'CC', 'full_name' => 'Juan Pérez Sintético', 'birth_date' => '1985-04-12', 'phone' => '3001112233']
        );
        $p2 = Patient::updateOrCreate(
            ['document_number' => '1000654321'],
            ['document_type' => 'CC', 'full_name' => 'María Gómez Ficticia', 'birth_date' => '1992-09-23', 'phone' => '3004445566']
        );
        $p3 = Patient::updateOrCreate(
            ['document_number' => '1000987654'],
            ['document_type' => 'CC', 'full_name' => 'Pedro Ramírez Prueba', 'birth_date' => '1978-11-05', 'phone' => '3007778899']
        );

        // Prescripción 1: Ideal para probar FEFO multi-lote (30 de Acetaminofén)
        $presc1 = Prescription::firstOrCreate(
            ['patient_id' => $p1->id, 'status' => 'ACTIVE'],
            ['doctor_id' => $doctor->id, 'valid_until' => now()->addDays(15)->toDateString(), 'notes' => 'Tratamiento analgésico general']
        );
        PrescriptionItem::firstOrCreate(
            ['prescription_id' => $presc1->id, 'product_id' => $products['MED-001']->id],
            ['prescribed_quantity' => 30, 'dispensed_quantity' => 0]
        );

        // Prescripción 2: Incluye medicamento de control especial (Morfina - RN-05)
        $presc2 = Prescription::firstOrCreate(
            ['patient_id' => $p2->id, 'status' => 'ACTIVE'],
            ['doctor_id' => $doctor->id, 'valid_until' => now()->addDays(7)->toDateString(), 'notes' => 'Manejo del dolor postoperatorio (Control Especial)']
        );
        PrescriptionItem::firstOrCreate(
            ['prescription_id' => $presc2->id, 'product_id' => $products['MED-006']->id],
            ['prescribed_quantity' => 5, 'dispensed_quantity' => 0]
        );

        // Prescripción 3: Tratamiento crónico (Losartán)
        $presc3 = Prescription::firstOrCreate(
            ['patient_id' => $p3->id, 'status' => 'ACTIVE'],
            ['doctor_id' => $doctor->id, 'valid_until' => now()->addDays(30)->toDateString(), 'notes' => 'Control hipertensión']
        );
        PrescriptionItem::firstOrCreate(
            ['prescription_id' => $presc3->id, 'product_id' => $products['MED-004']->id],
            ['prescribed_quantity' => 60, 'dispensed_quantity' => 0]
        );
    }
}