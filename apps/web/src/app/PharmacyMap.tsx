import { useEffect, useRef, useState } from 'react';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { useT } from '@sanara/i18n';
import {
  distanceLabel,
  statusLabel,
  type Position,
  type PublicPharmacy,
} from '../lib/publicSearch';

// Tuiles publiques OpenStreetMap hors production ; la production fournira ses propres tuiles (research.md).
const TILES =
  (import.meta.env.VITE_TILE_URL as string | undefined) ||
  'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
const CAMEROON: L.LatLngTuple = [7.4, 12.4];
const CELL = 48;

type Placed = PublicPharmacy & { latitude: number; longitude: number };

const pin = (className: string, text = '') =>
  L.divIcon({
    className,
    html: text,
    iconSize: [34, 34],
    iconAnchor: [17, 17],
  });

/** Carte des résultats : repères regroupés, résumé au survol ou à l'appui. */
export default function PharmacyMap({
  items,
  position,
  selected,
  onSelect,
  onOpen,
}: {
  items: PublicPharmacy[];
  position: Position | null;
  selected: string | null;
  onSelect: (id: string) => void;
  onOpen: (id: string) => void;
}) {
  const { t, locale } = useT();
  const box = useRef<HTMLDivElement>(null);
  const map = useRef<L.Map | null>(null);
  const layer = useRef<L.LayerGroup | null>(null);
  const [zoom, setZoom] = useState(6);

  useEffect(() => {
    const m = L.map(box.current!, { center: CAMEROON, zoom: 6 });
    L.tileLayer(TILES, { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(m);
    layer.current = L.layerGroup().addTo(m);
    m.on('zoomend', () => setZoom(m.getZoom()));
    map.current = m;
    return () => {
      m.remove();
      map.current = null;
    };
  }, []);

  const placed = items.filter((p): p is Placed => p.latitude !== null && p.longitude !== null);
  const key = placed.map((p) => p.id).join() + (position ? `@${position.lat},${position.lng}` : '');

  // Recadrage quand les résultats changent, pas quand on en choisit un.
  useEffect(() => {
    const points: L.LatLngTuple[] = placed.map((p) => [p.latitude, p.longitude]);
    if (position) points.push([position.lat, position.lng]);
    if (points.length)
      map.current?.fitBounds(L.latLngBounds(points), { padding: [48, 48], maxZoom: 15 });
  }, [key]);

  useEffect(() => {
    const target = placed.find((p) => p.id === selected);
    if (target) map.current?.panTo([target.latitude, target.longitude]);
  }, [selected]);

  useEffect(() => {
    const m = map.current;
    const group = layer.current;
    if (!m || !group) return;
    group.clearLayers();

    // Les repères trop proches à ce niveau de zoom sont réunis ; le repère choisi reste seul.
    const cells = new Map<string, Placed[]>();
    for (const p of placed) {
      const point = m.project([p.latitude, p.longitude], m.getZoom());
      const cell =
        p.id === selected || m.getZoom() >= 17
          ? p.id
          : `${Math.floor(point.x / CELL)}:${Math.floor(point.y / CELL)}`;
      cells.set(cell, [...(cells.get(cell) ?? []), p]);
    }

    for (const members of cells.values()) {
      const first = members[0]!;
      if (members.length > 1) {
        const bounds = L.latLngBounds(members.map((p) => [p.latitude, p.longitude]));
        const label = t('pub.cluster', { n: members.length });
        L.marker(bounds.getCenter(), {
          icon: pin('map-cluster', String(members.length)),
          title: label,
        })
          .on('click', () => m.setView(bounds.getCenter(), Math.min(m.getZoom() + 2, 18)))
          .addTo(group);
        continue;
      }
      // Contenu construit en texte : les noms viennent des pharmacies.
      const card = document.createElement('div');
      const name = document.createElement('b');
      name.textContent = first.pharmacy_name;
      const detail = document.createElement('div');
      detail.textContent = [
        first.distance_m === null ? null : distanceLabel(first.distance_m, locale),
        statusLabel(t, locale, first),
        first.duty_badge ? t(`duty.badge.${first.duty_badge}`) : null,
      ]
        .filter(Boolean)
        .join(' · ');
      card.append(name, detail);
      const chosen = first.id === selected;
      const marker = L.marker([first.latitude, first.longitude], {
        icon: pin(`map-pin${chosen ? ' map-pin--on' : ''}`),
        title: first.pharmacy_name,
        zIndexOffset: chosen ? 1000 : 0,
      })
        .bindTooltip(card, { direction: 'top', offset: [0, -14], permanent: chosen })
        .on('click', () => onSelect(first.id))
        .on('dblclick', () => onOpen(first.id));
      marker.addTo(group);
    }

    if (position)
      L.marker([position.lat, position.lng], {
        icon: pin('map-me'),
        title: t('pub.you'),
        interactive: false,
      }).addTo(group);
  }, [key, selected, zoom, locale]);

  return <div ref={box} className="map" role="region" aria-label={t('pub.map')} />;
}
