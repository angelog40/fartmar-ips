<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 0. Agregar rol a users con restricción CHECK (Sección 3)
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 30)->default('auxiliar_farmacia')->after('email');
        });
        DB::statement("
            ALTER TABLE users ADD CONSTRAINT chk_users_role 
            CHECK (role IN ('auxiliar_farmacia', 'regente_farmacia', 'medico', 'auditor', 'admin'))
        ");

        // 1. Bodegas (Sección 6)
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 2. Productos / Medicamentos (RN-01, RN-05)
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 50)->unique();
            $table->string('name');
            $table->string('unit', 30)->default('TABLETA');
            $table->boolean('is_special_control')->default(false);
            $table->timestamps();
        });

        // 3. Configuración de stock mínimo por bodega (RN-11)
        Schema::create('warehouse_product_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->integer('min_stock')->default(10);
            $table->timestamps();
            $table->unique(['warehouse_id', 'product_id']);
        });
        DB::statement('ALTER TABLE warehouse_product_settings ADD CONSTRAINT chk_min_stock_non_negative CHECK (min_stock >= 0)');

        // 4. Lotes con fecha de vencimiento (RN-01)
        Schema::create('batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('batch_number', 50);
            $table->date('expiration_date');
            $table->timestamps();
            $table->unique(['product_id', 'batch_number']);
        });

        // 5. Existencias: Bodega + Producto + Lote (RN-01, RN-03)
        Schema::create('inventories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('batch_id')->constrained()->restrictOnDelete();
            $table->integer('quantity')->default(0);
            $table->timestamps();
            $table->unique(['warehouse_id', 'product_id', 'batch_id']);
        });
        // RN-03: El stock nunca puede quedar negativo en la base de datos
        DB::statement('ALTER TABLE inventories ADD CONSTRAINT chk_inventory_quantity_non_negative CHECK (quantity >= 0)');

        // 6. Pacientes (RN-04, RN-10)
        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 10)->default('CC');
            $table->string('document_number', 50)->unique();
            $table->string('full_name');
            $table->date('birth_date');
            $table->string('phone', 30)->nullable();
            $table->timestamps();
        });

        // 7. Prescripciones y sus medicamentos (RN-04)
        Schema::create('prescriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('doctor_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('ACTIVE');
            $table->date('valid_until');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('prescription_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prescription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->integer('prescribed_quantity');
            $table->integer('dispensed_quantity')->default(0);
            $table->timestamps();
            $table->unique(['prescription_id', 'product_id']);
        });
        // RN-04: No se puede dispensar más de lo prescrito, permitiendo dispensaciones parciales acumuladas
        DB::statement('
            ALTER TABLE prescription_items 
            ADD CONSTRAINT chk_prescription_quantities 
            CHECK (prescribed_quantity > 0 AND dispensed_quantity >= 0 AND dispensed_quantity <= prescribed_quantity)
        ');

        // 8. Dispensaciones e ítems por lote (RN-02, RN-04, RN-05, RN-09)
        Schema::create('dispensations', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 100)->unique(); // RN-09
            $table->foreignId('prescription_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('dispensed_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('authorized_by')->nullable()->constrained('users')->restrictOnDelete(); // RN-05
            $table->timestamps();
        });

        Schema::create('dispensation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispensation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('batch_id')->constrained()->restrictOnDelete();
            $table->integer('quantity');
            $table->timestamps();
        });
        DB::statement('ALTER TABLE dispensation_items ADD CONSTRAINT chk_dispensation_qty_positive CHECK (quantity > 0)');

        // 9. Traslados entre bodegas (RN-07, RN-08)
        Schema::create('transfers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->foreignId('origin_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('destination_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('status', 30)->default('BORRADOR');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('dispatched_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('observations')->nullable();
            $table->timestamps();
        });
        DB::statement("
            ALTER TABLE transfers ADD CONSTRAINT chk_transfer_status 
            CHECK (status IN ('BORRADOR', 'SOLICITADO', 'APROBADO', 'EN_TRANSITO', 'RECIBIDO', 'RECIBIDO_PARCIAL', 'ANULADO'))
        ");
        DB::statement('ALTER TABLE transfers ADD CONSTRAINT chk_different_warehouses CHECK (origin_warehouse_id <> destination_warehouse_id)');
        // RN-08: Segregación de funciones: quien solicita un traslado no puede aprobarlo
        DB::statement('ALTER TABLE transfers ADD CONSTRAINT chk_segregation_of_duties CHECK (approved_by IS NULL OR requested_by <> approved_by)');

        Schema::create('transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('batch_id')->constrained()->restrictOnDelete();
            $table->integer('requested_qty');
            $table->integer('dispatched_qty')->default(0);
            $table->integer('received_qty')->default(0);
            $table->integer('discrepancy_qty')->default(0);
            $table->string('discrepancy_reason')->nullable();
            $table->timestamps();
        });
        DB::statement('ALTER TABLE transfer_items ADD CONSTRAINT chk_transfer_item_quantities CHECK (requested_qty > 0 AND dispatched_qty >= 0 AND received_qty >= 0)');

        // 10. Kardex Inmutable (RN-06)
        Schema::create('kardex_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('batch_id')->constrained()->restrictOnDelete();
            $table->string('type', 30);
            $table->integer('quantity');
            $table->integer('balance_after');
            $table->string('reference_type', 50)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reason')->nullable();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
        DB::statement("
            ALTER TABLE kardex_movements ADD CONSTRAINT chk_kardex_type 
            CHECK (type IN ('ENTRADA', 'SALIDA_DISPENSACION', 'SALIDA_TRASLADO', 'ENTRADA_TRASLADO', 'AJUSTE'))
        ");
        DB::statement('ALTER TABLE kardex_movements ADD CONSTRAINT chk_kardex_nonzero_qty CHECK (quantity <> 0 AND balance_after >= 0)');

        // RN-06: Trigger en PostgreSQL que impide editar o borrar movimientos del Kardex
        DB::unprepared("
            CREATE OR REPLACE FUNCTION prevent_kardex_modification()
            RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION 'Los movimientos del kardex son inmutables (RN-06). Use un movimiento de AJUSTE.';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_kardex_immutable
            BEFORE UPDATE OR DELETE ON kardex_movements
            FOR EACH ROW EXECUTE FUNCTION prevent_kardex_modification();
        ");

        // 11. Bitácora de Auditoría y Accesos a Pacientes (RN-10)
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('patient_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 100);
            $table->string('resource_type', 50)->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_kardex_immutable ON kardex_movements');
        DB::unprepared('DROP FUNCTION IF EXISTS prevent_kardex_modification');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('kardex_movements');
        Schema::dropIfExists('transfer_items');
        Schema::dropIfExists('transfers');
        Schema::dropIfExists('dispensation_items');
        Schema::dropIfExists('dispensations');
        Schema::dropIfExists('prescription_items');
        Schema::dropIfExists('prescriptions');
        Schema::dropIfExists('patients');
        Schema::dropIfExists('inventories');
        Schema::dropIfExists('batches');
        Schema::dropIfExists('warehouse_product_settings');
        Schema::dropIfExists('products');
        Schema::dropIfExists('warehouses');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS chk_users_role');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};