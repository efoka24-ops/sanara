import { useEffect, useState } from 'react';
import { api } from '../api/client';

export interface PublicPharmacy {
  id: string;
  pharmacy_name: string;
  country_code: string | null;
  region: string;
  department: string | null;
  city: string;
  district: string | null;
  address: string | null;
  phone: string | null;
  latitude: number | null;
  longitude: number | null;
  /** null : la pharmacie n'a pas communiqué ses horaires. */
  open: boolean | null;
  closes: string | null;
  next_opening: { weekday: number; opens: string; in_days: number } | null;
  distance_m: number | null;
  duty: Duty | null;
  /** De garde en ce moment, ou ce soir. */
  duty_badge: 'now' | 'tonight' | null;
}

/** Heures locales « 2026-11-27T20:00 ». */
export interface Duty {
  starts: string;
  ends: string;
  now: boolean;
}

export interface PublicSheet extends PublicPharmacy {
  week: { weekday: number; opens: string; closes: string }[] | null;
  duties: Duty[];
  services: string[];
  insurances: string[];
  email: string | null;
}

export interface SearchResult {
  items: PublicPharmacy[];
  total: number;
  origin: 'position' | 'district' | null;
}

export interface Place {
  country_code: string;
  region: string;
  department: string | null;
  city: string;
  district: string | null;
  pharmacies: number;
}

export type Position = { lat: number; lng: number };
type Translate = (key: string, vars?: Record<string, string | number>) => string;

/** « 21 h » ou « 8 h 30 » en français, « 21:00 » ailleurs. */
export function hour(time: string, locale: string): string {
  if (locale !== 'fr') return time;
  const [h, m] = time.split(':');
  return m === '00' ? `${Number(h)} h` : `${Number(h)} h ${m}`;
}

export function statusLabel(t: Translate, locale: string, p: PublicPharmacy): string {
  if (p.open === null) return t('pub.hoursUnknown');
  if (p.open) return t('pub.openUntil', { time: hour(p.closes ?? '', locale) });
  const next = p.next_opening;
  if (!next) return t('pub.closed');
  const time = hour(next.opens, locale);
  if (next.in_days === 0) return t('pub.opensToday', { time });
  if (next.in_days === 1) return t('pub.opensTomorrow', { time });
  // Le 1er janvier 2024 est un lundi : jour ISO 1.
  const day = new Intl.DateTimeFormat(locale, { weekday: 'long', timeZone: 'UTC' }).format(
    Date.UTC(2024, 0, next.weekday),
  );
  return t('pub.opensDay', { day, time });
}

export function dutyLabel(t: Translate, locale: string, duty: Duty): string {
  const day = (local: string) =>
    new Intl.DateTimeFormat(locale, {
      weekday: 'short',
      day: 'numeric',
      month: 'short',
      timeZone: 'UTC',
    }).format(new Date(`${local}Z`));
  const time = (local: string) => hour(local.slice(11), locale);
  return duty.now
    ? t('duty.until', { day: day(duty.ends), time: time(duty.ends) })
    : t('duty.from', { day: day(duty.starts), from: time(duty.starts), to: time(duty.ends) });
}

export function distanceLabel(metres: number, locale: string): string {
  if (metres < 1000) return `${Math.round(metres / 10) * 10} m`;
  return `${new Intl.NumberFormat(locale, { maximumFractionDigits: 1 }).format(metres / 1000)} km`;
}

export function directionsLink(p: PublicPharmacy): string {
  if (p.latitude !== null && p.longitude !== null)
    return `https://www.openstreetmap.org/directions?to=${p.latitude}%2C${p.longitude}`;
  const address = [p.address, p.district, p.city].filter(Boolean).join(', ') || p.pharmacy_name;
  return `https://www.openstreetmap.org/search?query=${encodeURIComponent(address)}`;
}

export const telLink = (phone: string) => `tel:${phone.replace(/\s/g, '')}`;

/**
 * Position du visiteur, demandée seulement à sa demande. Arrondie à une centaine de mètres : le serveur
 * n'a pas besoin de mieux pour trier par distance.
 */
export function locate(): Promise<Position> {
  return new Promise((resolve, reject) => {
    if (!navigator.geolocation) {
      reject(new Error('unavailable'));
      return;
    }
    navigator.geolocation.getCurrentPosition(
      ({ coords }) =>
        resolve({
          lat: Math.round(coords.latitude * 1000) / 1000,
          lng: Math.round(coords.longitude * 1000) / 1000,
        }),
      reject,
      { timeout: 10000, maximumAge: 300000 },
    );
  });
}

/** Lieux où des pharmacies sont publiées, pour les listes en cascade. */
export function usePlaces(): Place[] {
  const [places, setPlaces] = useState<Place[]>([]);
  useEffect(() => {
    let live = true;
    api<{ items: Place[] }>('GET', '/public/geo')
      .then((r) => live && setPlaces(r.items))
      .catch(() => undefined); // la recherche par texte reste possible
    return () => {
      live = false;
    };
  }, []);
  return places;
}

export const distinct = (values: Array<string | null>): string[] =>
  [...new Set(values.filter((v): v is string => !!v))].sort((a, b) => a.localeCompare(b));
