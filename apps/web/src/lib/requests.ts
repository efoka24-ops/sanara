/** Demandes patients (US21 à US26) : types et formats partagés par le site public et l'espace pharmacie. */

export type RequestStatus =
  | 'received'
  | 'processing'
  | 'photo_needed'
  | 'quoted'
  | 'accepted'
  | 'ready'
  | 'picked_up'
  | 'cancelled'
  | 'expired';

export const OPEN_STATUSES: RequestStatus[] = [
  'received',
  'processing',
  'photo_needed',
  'quoted',
  'accepted',
  'ready',
];

export interface QuoteLine {
  id: string;
  label: string;
  quantity: number;
  unit_price: number;
  total: number;
  available: boolean;
  accepted: boolean | null;
}

export interface PatientView {
  number: string;
  status: RequestStatus;
  urgent: boolean;
  advice: 'emergency' | null;
  first_name: string;
  created_at: string;
  pages: number;
  pharmacy: {
    id: string | null;
    name: string;
    phone: string | null;
    open: boolean | null;
    closes: string | null;
    next_opening: { weekday: number; opens: string; in_days: number } | null;
  };
  events: { kind: string; at: string }[];
  quote: { total: number; valid_until: string; lines: QuoteLine[] } | null;
  pickup_code: string | null;
  pickup_until: string | null;
}

/** Date et heure du serveur (UTC) affichées à l'heure de l'appareil. */
export function when(stamp: string, locale: string): string {
  return new Intl.DateTimeFormat(locale === 'fr' ? 'fr-FR' : 'en-GB', {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
  }).format(new Date(`${stamp.replace(' ', 'T')}Z`));
}

const MAX_SIDE = 1600;
/** Corps JSON accepté par l'hébergeur (8 Mo), base64 compris. */
export const MAX_TOTAL_BYTES = 5_500_000;

/**
 * Photo réduite à 1 600 px de côté en JPEG : l'envoi passe sur un réseau lent et sous la limite du serveur.
 * Les PDF et les images déjà légères restent tels quels.
 */
export async function shrink(file: File): Promise<Blob> {
  if (!file.type.startsWith('image/') || file.size < 400_000) return file;
  try {
    const image = await createImageBitmap(file);
    const scale = Math.min(1, MAX_SIDE / Math.max(image.width, image.height));
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(image.width * scale);
    canvas.height = Math.round(image.height * scale);
    canvas.getContext('2d')!.drawImage(image, 0, 0, canvas.width, canvas.height);
    image.close();
    const blob = await new Promise<Blob | null>((resolve) =>
      canvas.toBlob(resolve, 'image/jpeg', 0.82),
    );
    return blob && blob.size < file.size ? blob : file;
  } catch {
    // Format que le navigateur ne sait pas lire : le serveur dira s'il l'accepte.
    return file;
  }
}

export const toBase64 = (blob: Blob): Promise<string> =>
  new Promise((resolve, reject) => {
    const r = new FileReader();
    r.onload = () => resolve(String(r.result).split(',')[1] ?? '');
    r.onerror = () => reject(r.error);
    r.readAsDataURL(blob);
  });
