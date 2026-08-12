"use client";

import { ReactNode } from "react";

export function PageHeader({
  title,
  subtitle,
  actions,
}: {
  title: string;
  subtitle?: ReactNode;
  actions?: ReactNode;
}) {
  return (
    <header className="page-header">
      <div className="page-header-main">
        <h1 className="page-title">{title}</h1>
        {subtitle && <p className="page-sub">{subtitle}</p>}
      </div>
      {actions && <div className="page-actions">{actions}</div>}
    </header>
  );
}

export function Flash({ children, tone = "info" }: { children: ReactNode; tone?: "info" | "error" | "ok" }) {
  if (!children) return null;
  return <div className={`flash ${tone === "info" ? "" : tone}`}>{children}</div>;
}

export function Tabs({
  items,
  value,
  onChange,
}: {
  items: Array<{ id: string; label: string }>;
  value: string;
  onChange: (id: string) => void;
}) {
  return (
    <div className="tabs" role="tablist">
      {items.map((item) => (
        <button
          key={item.id}
          type="button"
          role="tab"
          aria-selected={value === item.id}
          className={value === item.id ? "tab active" : "tab"}
          onClick={() => onChange(item.id)}
        >
          {item.label}
        </button>
      ))}
    </div>
  );
}

export function Drawer({
  open,
  title,
  subtitle,
  onClose,
  children,
}: {
  open: boolean;
  title: string;
  subtitle?: string;
  onClose: () => void;
  children: ReactNode;
}) {
  if (!open) return null;
  return (
    <>
      <div className="drawer-backdrop" onClick={onClose} />
      <aside className="drawer" role="dialog" aria-modal="true" aria-label={title}>
        <div className="drawer-header">
          <div>
            <h2>{title}</h2>
            {subtitle && <p className="muted" style={{ margin: "6px 0 0" }}>{subtitle}</p>}
          </div>
          <button className="btn secondary sm" type="button" onClick={onClose}>
            Schließen
          </button>
        </div>
        <div className="drawer-body">{children}</div>
      </aside>
    </>
  );
}

export function Empty({ title, text }: { title: string; text?: string }) {
  return (
    <div className="empty">
      <strong>{title}</strong>
      {text && <span>{text}</span>}
    </div>
  );
}

export function Section({
  title,
  note,
  action,
  children,
  bare = false,
}: {
  title: string;
  note?: string;
  action?: ReactNode;
  children: ReactNode;
  bare?: boolean;
}) {
  return (
    <section className="section">
      <div className="section-head">
        <div>
          <h2 className="section-title">{title}</h2>
          {note && <p className="section-note">{note}</p>}
        </div>
        {action}
      </div>
      {bare ? children : <div className="surface surface-pad">{children}</div>}
    </section>
  );
}
