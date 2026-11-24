/** Client HTTP : JSON UTF-8 brut (le pare-feu Camoo refuse les séquences \uXXXX, voir research §6). */
export class ApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly code: string,
    public readonly details: Record<string, unknown> = {},
  ) {
    super(code);
  }
}

const BASE = (import.meta.env.VITE_API_URL as string | undefined) ?? '/api';
let accessToken: string | null = null;
let refreshToken: string | null = null;
export const setTokens = (a: string | null, r: string | null) => {
  accessToken = a;
  refreshToken = r;
};

/** JSON.stringify en évitant tout \uXXXX : caractères de contrôle seulement échappés, le reste reste brut. */
export function rawJson(value: unknown): string {
  return JSON.stringify(value);
}

async function raw(method: string, path: string, body?: unknown, retry = true): Promise<Response> {
  const headers: Record<string, string> = {
    Accept: 'application/json',
    'Accept-Language': document.documentElement.lang || 'fr',
  };
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (accessToken) headers.Authorization = `Bearer ${accessToken}`;
  const res = await fetch(`${BASE}${path}`, {
    method,
    headers,
    body: body === undefined ? undefined : rawJson(body),
  });
  if (res.status === 401 && retry && refreshToken && !path.startsWith('/auth/')) {
    const r = await fetch(`${BASE}/auth/refresh`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: rawJson({ refresh: refreshToken }),
    });
    if (r.ok) {
      const t = (await r.json()) as { access: string; refresh: string };
      setTokens(t.access, t.refresh);
      return raw(method, path, body, false);
    }
    setTokens(null, null);
  }
  return res;
}

export async function api<T>(method: string, path: string, body?: unknown): Promise<T> {
  const res = await raw(method, path, body);
  if (res.status === 204) return undefined as T;
  const parsed: unknown = await res.json().catch(() => null);
  // Une réponse qui n'est pas du JSON (page d'erreur PHP, proxy) ne doit jamais passer pour un succès.
  if (parsed === null || typeof parsed !== 'object')
    throw new ApiError(res.status || 500, 'server_error');
  const data = parsed as {
    error?: { code: string; details?: Record<string, unknown> };
  } & T;
  if (!res.ok)
    throw new ApiError(res.status, data.error?.code ?? 'server_error', data.error?.details ?? {});
  return data;
}

export const readAsBase64 = (file: File): Promise<string> =>
  new Promise((resolve, reject) => {
    const r = new FileReader();
    r.onload = () => resolve(String(r.result).split(',')[1] ?? '');
    r.onerror = () => reject(r.error);
    r.readAsDataURL(file);
  });

/** En-tête d'autorisation pour les téléchargements (fetch direct). */
export const accessHeader = (): Record<string, string> =>
  accessToken ? { Authorization: `Bearer ${accessToken}` } : {};
