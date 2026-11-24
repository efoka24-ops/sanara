export type LocalOperation = {
  id: string;
  opType: string;
  payloadJson: string;
  createdAt: string;
  ackedAt: string | null;
};

const operations = new Map<string, LocalOperation>();

export function enqueueOperation(op: Omit<LocalOperation, 'ackedAt'>): LocalOperation {
  const existing = operations.get(op.id);
  if (existing) {
    return existing;
  }

  const created: LocalOperation = {
    ...op,
    ackedAt: null,
  };
  operations.set(created.id, created);
  return created;
}

export function listPendingOperations(limit: number): LocalOperation[] {
  const safeLimit = Math.max(0, limit);
  return [...operations.values()]
    .filter((op) => op.ackedAt === null)
    .sort((a, b) => a.createdAt.localeCompare(b.createdAt) || a.id.localeCompare(b.id))
    .slice(0, safeLimit);
}

export function markAcked(id: string): LocalOperation | null {
  const op = operations.get(id);
  if (!op) {
    return null;
  }

  const updated: LocalOperation = {
    ...op,
    ackedAt: new Date().toISOString(),
  };
  operations.set(id, updated);
  return updated;
}
