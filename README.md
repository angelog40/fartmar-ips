# FARTMAR IPS — Sistema Clínico de Inventario Farmacéutico, Dispensación FEFO y Traslados

Solución full-stack para el control de inventarios farmacéuticos por lote, dispensación bajo algoritmo **FEFO (First Expired, First Out)** seguro ante concurrencia, máquina de estados para traslados entre bodegas con segregación de funciones, kardex contable inmutable y asistente de inventario con Inteligencia Artificial y guardrails de seguridad.

---

## 1. Stack Tecnológico y Arquitectura

* **Base de Datos:** PostgreSQL 16 (con restricciones `CHECK` nativas, bloqueo pesimista `SELECT ... FOR UPDATE` y `TRIGGER` en `plpgsql` para inmutabilidad del Kardex).
* **Backend API:** PHP 8.3 + Laravel 13 + Laravel Sanctum. Arquitectura orientada a servicios de dominio (`DispensationService`, `TransferService`, `KardexService`, `InventoryAlertService`, `AiInventoryAssistantService`) desacoplados de los controladores HTTP.
* **Frontend SPA:** React + TypeScript + Vite con tipado estricto.
* **Infraestructura y Calidad:** Docker Compose (imágenes multi-stage ejecutadas con usuarios no-root), Laravel Pint, Larastan (PHPStan), PHPUnit y GitHub Actions CI.

---

## 2. Cumplimiento de Reglas de Negocio (RN-01 a RN-11)

| Regla | Descripción | Implementación en Código y Base de Datos |
| :--- | :--- | :--- |
| **RN-01** | Inventario por Bodega + Producto + Lote con exclusión de vencidos | Índice `UNIQUE (warehouse_id, product_id, batch_id)` en `inventories`; filtro `expiration_date > CURRENT_DATE` en dispensación y validación `$batch->isExpired()` en traslados. |
| **RN-02** | Dispensación FEFO multi-lote | `DispensationService::dispense()` ordena por `batches.expiration_date ASC` y consume secuencialmente de múltiples lotes vigentes hasta cubrir la cantidad solicitada. |
| **RN-03** | Stock nunca negativo ni bajo concurrencia | Transacción atómica `DB::transaction` + bloqueo de fila `->lockForUpdate()` (`SELECT ... FOR UPDATE`) + restricción SQL `chk_inventory_quantity_non_negative CHECK (quantity >= 0)`. |
| **RN-04** | Dispensación contra prescripción vigente (parcial o total) | Bloqueo de prescripción, control de saldo pendiente (`prescribed_quantity - dispensed_quantity`) y restricción SQL `chk_prescription_quantities`. |
| **RN-05** | Doble firma para medicamentos de control especial | `verifySpecialControlAuthorization()` exige credenciales de un **segundo** usuario con rol `regente_farmacia` (`$authorizer->id !== $actor->id`). |
| **RN-06** | Kardex inmutable y correcciones por ajuste con motivo | Modelo `KardexMovement` bloquea `updating/deleting` y el trigger SQL `trg_kardex_immutable` aborta cualquier `UPDATE` o `DELETE` en PostgreSQL. Ajustes vía `KardexService::adjustInventory()`. |
| **RN-07** | Flujo de traslados y discrepancias en recepción | Estados `BORRADOR -> SOLICITADO -> APROBADO -> EN_TRANSITO -> RECIBIDO / RECIBIDO_PARCIAL` o `ANULADO`. Descuenta stock en origen al despachar y calcula `discrepancy_qty` al recibir. |
| **RN-08** | Segregación de funciones en traslados | Validación en `TransferService::approve()` y restricción SQL `chk_segregation_of_duties CHECK (approved_by IS NULL OR requested_by <> approved_by)`. |
| **RN-09** | Idempotencia en operaciones críticas | Columna `idempotency_key UNIQUE` en `dispensations`. Reintentos con la misma llave devuelven la transacción original sin descontar stock dos veces. |
| **RN-10** | Privacidad de pacientes y auditoría de accesos | `PatientResource` enmascara documento, nombre, fecha de nacimiento y teléfono para el rol `auditor`. Registro automático en `audit_logs` y logs con `X-Correlation-ID` sin PII. |
| **RN-11** | Alertas de vencimiento (<= 90 días) y stock mínimo | `InventoryAlertService::getAlerts()` calcula lotes vencidos/próximos a vencer y déficit de stock vigente frente al mínimo configurado por bodega. |

---

## 3. Ejecución Rápida

### Opción A: Levantar todo el entorno con Docker Compose
```bash
docker compose up -d --build