/** Ticket et rapport Z imprimés hors-ligne. Mêmes libellés que apps/api/app/Modules/Sales/SalesDocuments.php. */
import type { PharmacyHeader } from '@sanara/sync';
import type { SaleRecord, SessionReport } from '../pos/local-pos';

const METHODS: Record<string, string> = {
  cash: 'Espèces',
  momo_mtn: 'MTN MoMo',
  momo_orange: 'Orange Money',
  card: 'Carte',
  credit: 'Crédit',
};

const esc = (v: unknown) =>
  String(v ?? '').replace(
    /[&<>"']/g,
    (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[c]!,
  );
const money = (v: number, currency: string) =>
  `${String(v).replace(/\B(?=(\d{3})+(?!\d))/g, ' ')} ${currency === 'XAF' ? 'FCFA' : esc(currency)}`;
const row = (label: string, value: string, cls = '') =>
  `<tr${cls ? ` class="${cls}"` : ''}><td>${label}</td><td class="n">${value}</td></tr>`;
const SEP = '<div class="sep"></div>';

function page(title: string, body: string, width: '58' | '80'): string {
  const mm = `${width}mm`;
  return (
    `<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>${esc(title)}</title><style>` +
    `@page{size:${mm} auto;margin:3mm}body{font-family:system-ui,Segoe UI,Arial,sans-serif;font-size:9pt;color:#111;max-width:${mm};margin:0 auto;padding:8px}` +
    'table{width:100%;border-collapse:collapse}td{padding:2px 0;text-align:left;vertical-align:top}td.n{text-align:right;white-space:nowrap}' +
    '.c{text-align:center}.muted{color:#555}.sep{border-top:1px dashed #333;margin:6px 0}.tot td{font-weight:700}' +
    '.dup{border:2px solid #000;padding:2px 6px;display:inline-block;font-weight:700;letter-spacing:.1em}' +
    `@media print{.noprint{display:none}}</style></head><body>${body}` +
    '<p class="noprint c"><button onclick="window.print()">Imprimer</button></p></body></html>'
  );
}

export function receipt(sale: SaleRecord, shop: PharmacyHeader, duplicate: boolean): string {
  const cur = shop.currency;
  let body =
    `<div class="c"><b>${esc(shop.name)}</b><br>${esc(shop.address)}<br>Tél ${esc(shop.phone)}<br>Agrément ${esc(shop.licenseNumber)}</div>` +
    (duplicate ? '<p class="c"><span class="dup">DUPLICATA</span></p>' : '') +
    `${SEP}<div>Ticket ${esc(sale.number)}<br>${esc(sale.createdAt.slice(0, 16).replace('T', ' '))} UTC</div>${SEP}<table>`;
  for (const l of sale.lines) {
    const discount = l.discountBp > 0 ? ` (−${l.discountBp / 100} %)` : '';
    body +=
      `<tr><td colspan="2">${esc(l.name)}</td></tr>` +
      row(`${l.qty} × ${money(l.unitPrice, cur)}${discount}`, money(l.total, cur));
  }
  body += `</table>${SEP}<table>`;
  if (sale.discountTotal > 0) body += row('Remises', `−${money(sale.discountTotal, cur)}`);
  body +=
    row('TOTAL TTC', money(sale.total, cur), 'tot') + row('dont TVA', money(sale.vatTotal, cur));
  for (const p of sale.payments) body += row(esc(METHODS[p.method]), money(p.tendered, cur));
  if (sale.change > 0) body += row('Rendu', money(sale.change, cur));
  body += '</table>';
  if (sale.prescription) {
    body += `${SEP}<div class="muted">Ordonnance ${esc(sale.prescription.ref)} · ${esc(sale.prescription.prescriber)}</div>`;
  }
  body += `${SEP}<p class="c muted">${esc(shop.ticketFooter || 'Merci de votre visite')}</p>`;
  return page(`Ticket ${sale.number}`, body, shop.ticketWidth === '58' ? '58' : '80');
}

export function zReport(report: SessionReport, shop: PharmacyHeader): string {
  const cur = shop.currency;
  let body =
    `<div class="c"><b>${esc(shop.name)}</b><br>Rapport Z ${esc(report.closedAt ? 'poste' : '(session ouverte)')}</div>${SEP}` +
    `<div>Caisse ${esc(report.station)}<br>Ouverture ${esc(report.openedAt.slice(0, 16).replace('T', ' '))}<br>Clôture ${esc((report.closedAt ?? '').slice(0, 16).replace('T', ' '))}</div>${SEP}<table>` +
    row('Ventes', String(report.salesCount)) +
    row('Chiffre d’affaires TTC', money(report.salesTotal, cur)) +
    row('dont TVA', money(report.vatTotal, cur)) +
    `</table>${SEP}<table>`;
  for (const [method, amount] of Object.entries(report.byMethod)) {
    body += row(esc(METHODS[method]), money(amount, cur));
  }
  body +=
    `</table>${SEP}<table>` +
    row('Fond de caisse', money(report.openingFloat, cur)) +
    row('Espèces attendues', money(report.expectedCash, cur));
  if (report.counted !== null) {
    body +=
      row('Espèces comptées', money(report.counted, cur)) +
      row('Écart', money(report.counted - report.expectedCash, cur), 'tot');
    if (report.gapReason) {
      body += `<tr><td colspan="2" class="muted">Motif : ${esc(report.gapReason)}</td></tr>`;
    }
  }
  return page('Rapport Z', `${body}</table>`, shop.ticketWidth === '58' ? '58' : '80');
}
