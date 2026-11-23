import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useId,
  useState,
  type ButtonHTMLAttributes,
  type InputHTMLAttributes,
  type ReactNode,
} from 'react';
import './components.css';

const cx = (...c: Array<string | false | undefined>) => c.filter(Boolean).join(' ');

export type ButtonVariant = 'primary' | 'secondary' | 'danger' | 'warning';
export function Button({
  variant = 'primary',
  size,
  block,
  className,
  ...p
}: ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: ButtonVariant;
  size?: 'sm';
  block?: boolean;
}) {
  return (
    <button
      {...p}
      className={cx(
        'sa-btn',
        variant !== 'primary' && `sa-btn--${variant}`,
        size === 'sm' && 'sa-btn--sm',
        block && 'sa-btn--block',
        className,
      )}
    />
  );
}

export function Field({
  label,
  error,
  className,
  id,
  ...p
}: InputHTMLAttributes<HTMLInputElement> & { label: string; error?: string }) {
  const auto = useId();
  const fid = id ?? auto;
  return (
    <div>
      <label className={cx('sa-field', error && 'sa-field--error', className)} htmlFor={fid}>
        <span className="sa-field__label">{label}</span>
        <input
          {...p}
          id={fid}
          className="sa-field__input"
          aria-invalid={error ? true : undefined}
          aria-describedby={error ? `${fid}-e` : undefined}
        />
      </label>
      {error && (
        <div id={`${fid}-e`} role="alert" className="sa-field__error">
          {error}
        </div>
      )}
    </div>
  );
}

export type BadgeTone = 'ok' | 'wait' | 'danger' | 'neutral';
export function Badge({ tone = 'neutral', children }: { tone?: BadgeTone; children: ReactNode }) {
  return (
    <span className={cx('sa-badge', tone !== 'neutral' && `sa-badge--${tone}`)}>{children}</span>
  );
}

export function Switch({
  checked,
  onChange,
  label,
}: {
  checked: boolean;
  onChange: (v: boolean) => void;
  label: string;
}) {
  return (
    <button
      type="button"
      role="switch"
      aria-checked={checked}
      aria-label={label}
      className="sa-switch"
      onClick={() => onChange(!checked)}
    />
  );
}

export function Stepper({ steps, current }: { steps: string[]; current: number }) {
  return (
    <div className="sa-stepper" role="list">
      {steps.map((s, i) => (
        <div
          key={s}
          role="listitem"
          aria-current={i === current ? 'step' : undefined}
          className={cx(
            'sa-stepper__step',
            i < current && 'sa-stepper__step--done',
            i === current && 'sa-stepper__step--current',
          )}
        >
          <div className="sa-stepper__bar" />
          <div className="sa-stepper__label">
            {i + 1} {s}
          </div>
        </div>
      ))}
    </div>
  );
}

export interface Column<T> {
  key: string;
  header: string;
  render: (row: T) => ReactNode;
}
export function Table<T>({
  columns,
  rows,
  rowKey,
}: {
  columns: Column<T>[];
  rows: T[];
  rowKey: (r: T) => string;
}) {
  return (
    <table className="sa-table">
      <thead>
        <tr>
          {columns.map((c) => (
            <th key={c.key} scope="col">
              {c.header}
            </th>
          ))}
        </tr>
      </thead>
      <tbody>
        {rows.map((r) => (
          <tr key={rowKey(r)}>
            {columns.map((c) => (
              <td key={c.key}>{c.render(r)}</td>
            ))}
          </tr>
        ))}
      </tbody>
    </table>
  );
}

export function Modal({
  title,
  onClose,
  children,
}: {
  title: string;
  onClose: () => void;
  children: ReactNode;
}) {
  useEffect(() => {
    const h = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
    window.addEventListener('keydown', h);
    return () => window.removeEventListener('keydown', h);
  }, [onClose]);
  return (
    <div className="sa-modal__backdrop" onClick={onClose}>
      <div
        className="sa-modal"
        role="dialog"
        aria-modal="true"
        aria-label={title}
        onClick={(e) => e.stopPropagation()}
      >
        <h2 className="sa-modal__title">{title}</h2>
        {children}
      </div>
    </div>
  );
}

interface ToastItem {
  id: number;
  text: string;
  tone: 'info' | 'error';
}
const ToastCtx = createContext<(text: string, tone?: 'info' | 'error') => void>(() => undefined);
export const useToast = () => useContext(ToastCtx);
export function ToastProvider({ children }: { children: ReactNode }) {
  const [items, setItems] = useState<ToastItem[]>([]);
  const push = useCallback((text: string, tone: 'info' | 'error' = 'info') => {
    const id = Date.now() + Math.random();
    setItems((x) => [...x, { id, text, tone }]);
    setTimeout(() => setItems((x) => x.filter((i) => i.id !== id)), 4000);
  }, []);
  return (
    <ToastCtx.Provider value={push}>
      {children}
      <div className="sa-toasts" aria-live="polite">
        {items.map((i) => (
          <div key={i.id} className={cx('sa-toast', i.tone === 'error' && 'sa-toast--error')}>
            {i.text}
          </div>
        ))}
      </div>
    </ToastCtx.Provider>
  );
}
