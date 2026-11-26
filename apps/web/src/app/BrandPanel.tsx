export type Illustration = 'officine' | 'hors-ligne' | 'carte' | 'verification' | 'stock';

/** Image décorative d'un panneau vert (fichiers générés par scripts/illustrations.mjs). */
export function Art({ name, className = '' }: { name: Illustration; className?: string }) {
  return <img className={`art ${className}`} src={`/images/${name}.svg`} alt="" />;
}

/** Panneau vert de la maquette (A1, A7) : illustration, accroche et texte. */
export function BrandPanel({
  image,
  title,
  lead,
  className = '',
}: {
  image: Illustration;
  title: string;
  lead: string;
  className?: string;
}) {
  return (
    <aside className={`brand-panel ${className}`}>
      <Art name={image} />
      <p className="brand-panel__title">{title}</p>
      <p className="brand-panel__lead">{lead}</p>
    </aside>
  );
}
