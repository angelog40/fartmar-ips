export interface User {
  id: number;
  name: string;
  email: string;
  role: 'auxiliar_farmacia' | 'regente_farmacia' | 'medico' | 'auditor' | 'admin';
}

export interface Warehouse {
  id: number;
  code: string;
  name: string;
}

export interface Batch {
  id: number;
  product_id: number;
  batch_number: string;
  expiration_date: string;
}

export interface Product {
  id: number;
  sku: string;
  name: string;
  unit: string;
  is_special_control: boolean;
  batches?: Batch[];
}

export interface InventoryItem {
  id: number;
  warehouse_id: number;
  warehouse_name: string;
  product_id: number;
  product_sku: string;
  product_name: string;
  unit: string;
  is_special_control: boolean;
  batch_id: number;
  batch_number: string;
  expiration_date: string;
  days_remaining: number;
  expiration_status: 'VENCIDO' | 'CRITICO_30D' | 'ALERTA_90D' | 'VIGENTE';
  quantity: number;
}

export interface ExpiringBatchAlert {
  inventory_id: number;
  warehouse_id: number;
  warehouse_name: string;
  product_id: number;
  product_name: string;
  batch_id: number;
  batch_number: string;
  expiration_date: string;
  days_remaining: number;
  status: string;
  quantity: number;
}

export interface LowStockAlert {
  warehouse_id: number;
  warehouse_name: string;
  product_id: number;
  product_sku: string;
  product_name: string;
  min_stock: number;
  valid_stock: number;
  deficit: number;
}

export interface KardexMovement {
  id: number;
  type: string;
  quantity: number;
  balance_after: number;
  reason: string | null;
  created_at: string;
  warehouse?: { name: string };
  product?: { name: string };
  batch?: { batch_number: string };
  user?: { name: string };
}

export interface Patient {
  id: number;
  document_type: string;
  document_number: string;
  full_name: string;
  birth_date: string;
  phone: string;
  is_masked: boolean;
}

export interface PrescriptionItem {
  id: number;
  product_id: number;
  product_sku: string;
  product_name: string;
  unit: string;
  is_special_control: boolean;
  prescribed_quantity: number;
  dispensed_quantity: number;
  pending_quantity: number;
}

export interface Prescription {
  id: number;
  status: string;
  valid_until: string;
  notes: string | null;
  doctor_name: string;
  patient: Patient;
  items: PrescriptionItem[];
}

export interface FefoAllocation {
  batch_id: number;
  batch_number: string;
  expiration_date: string;
  available_qty: number;
  allocated_qty: number;
}

export interface FefoPreview {
  product_id: number;
  requested_qty: number;
  fulfilled: boolean;
  missing_qty: number;
  allocations: FefoAllocation[];
}

export interface DispensationRecord {
  id: number;
  idempotency_key: string;
  prescription_id: number;
  warehouse_name: string;
  dispensed_by: string;
  authorized_by: string | null;
  created_at: string;
  patient: Patient;
  items: Array<{
    product_name: string;
    batch_number: string;
    expiration_date: string;
    quantity: number;
  }>;
}

export interface TransferItem {
  id: number;
  product_id: number;
  batch_id: number;
  requested_qty: number;
  dispatched_qty: number;
  received_qty: number;
  discrepancy_qty: number;
  discrepancy_reason: string | null;
  product?: { name: string; sku: string };
  batch?: { batch_number: string; expiration_date: string };
}

export interface Transfer {
  id: number;
  code: string;
  origin_warehouse_id: number;
  destination_warehouse_id: number;
  status: 'BORRADOR' | 'SOLICITADO' | 'APROBADO' | 'EN_TRANSITO' | 'RECIBIDO' | 'RECIBIDO_PARCIAL' | 'ANULADO';
  requested_by: number;
  approved_by: number | null;
  observations: string | null;
  origin_warehouse?: { name: string };
  destination_warehouse?: { name: string };
  requester?: { name: string };
  approver?: { name: string };
  items: TransferItem[];
}

export interface AiResponse {
  provider: string;
  blocked: boolean;
  reason?: string;
  tools_invoked: string[];
  answer: string;
}

export interface AuditLogItem {
  id: number;
  user_id: number | null;
  patient_id: number | null;
  action: string;
  resource_type: string | null;
  correlation_id: string | null;
  ip_address: string | null;
  created_at: string;
  user?: { name: string; email: string; role: string };
}

const API_BASE = import.meta.env.VITE_API_URL || '/api';

export async function apiFetch<T>(
  endpoint: string,
  options: RequestInit = {},
  token?: string | null
): Promise<T> {
  const headers: Record<string, string> = {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  };

  if (token) {
    headers.Authorization = `Bearer ${token}`;
  }

  const response = await fetch(`${API_BASE}${endpoint}`, {
    ...options,
    headers: {
      ...headers,
      ...(options.headers as Record<string, string> | undefined),
    },
  });

  const correlationId = response.headers.get('X-Correlation-ID');
  const body = (await response.json().catch(() => ({}))) as {
    message?: string;
    correlation_id?: string;
  } & T;

  if (!response.ok) {
    const corr = body.correlation_id || correlationId;
    const msg = body.message || `Error HTTP ${response.status}`;
    throw new Error(corr ? `${msg} [Correlation-ID: ${corr.slice(0, 8)}]` : msg);
  }

  return body;
}