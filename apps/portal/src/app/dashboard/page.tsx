"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { Shell } from "@/components/Shell";
import { ProcessList } from "@/components/ProcessBar";
import { Flash, PageHeader, Section } from "@/components/ui";
import { api } from "@/lib/api";

type QueueItem = {
  severity: string;
  kind: string;
  title: string;
  detail?: string | null;
  site_id?: string | null;
  href: string;
};

type Dashboard = {
  sites_total: number;
  sites_online: number;
  sites_offline: number;
  http_down: number;
  ssl_expiring: number;
  eol: number;
  backup_unhealthy: number;
  hardening_drift: number;
  failed_logins_24h: number;
  open_vulnerabilities: number;
  needs_review: number;
  hours_included_month: number;
  hours_used_month: number;
  queue: QueueItem[];
  recent_events: Array<{
    id: string;
    type: string;
    title: string;
    severity: string;
    occurred_at: string;
    site_id: string;
  }>;
  active_jobs?: Array<{
    id: string;
    site_name?: string;
    progress_ui?: { percent: number; label?: string; title?: string; status?: string };
  }>;
};

export default function DashboardPage() {
  const router = useRouter();
  const [data, setData] = useState<Dashboard | null>(null);
  const [error, setError] = useState("");

  useEffect(() => {
    api<Dashboard>("/dashboard")
      .then(setData)
      .catch((e) => {
        setError(e.message);
        if (String(e.message).includes("Unauthenticated") || String(e.message).includes("401")) {
          router.push("/login");
        }
      });
    const t = setInterval(() => {
      api<Dashboard>("/dashboard").then(setData).catch(() => undefined);
    }, 8000);
    return () => clearInterval(t);
  }, [router]);

  return (
    <Shell>
      <PageHeader
        title="Leitstand"
        subtitle="Was heute brennt – Flotte, Backups, Reviews, Stunden."
        actions={<Link className="btn secondary" href="/projects">Neues Projekt</Link>}
      />
      <Flash tone="error">{error}</Flash>

      {data && (
        <>
          <div className="grid stats" style={{ marginBottom: 28 }}>
            <div className={`stat ${data.queue.filter((q) => q.severity === "error").length ? "alert" : ""}`}>
              <div className="stat-value">{data.queue.length}</div>
              <div className="stat-label">Offene Punkte</div>
            </div>
            <div className={`stat ${data.sites_offline + data.http_down > 0 ? "emphasis" : ""}`}>
              <div className="stat-value">{data.sites_offline + data.http_down}</div>
              <div className="stat-label">Offline / HTTP down</div>
            </div>
            <div className={`stat ${data.open_vulnerabilities > 0 ? "alert" : ""}`}>
              <div className="stat-value">{data.open_vulnerabilities}</div>
              <div className="stat-label">Offene Schwachstellen</div>
            </div>
            <div className={`stat ${data.needs_review > 0 ? "emphasis" : ""}`}>
              <div className="stat-value">{data.needs_review}</div>
              <div className="stat-label">Reviews</div>
            </div>
            <div className="stat">
              <div className="stat-value">{data.hours_used_month}/{data.hours_included_month}</div>
              <div className="stat-label">Stunden diesen Monat</div>
            </div>
            <div className={`stat ${data.backup_unhealthy > 0 ? "emphasis" : ""}`}>
              <div className="stat-value">{data.backup_unhealthy}</div>
              <div className="stat-label">Backup-Probleme</div>
            </div>
            <div className={`stat ${data.ssl_expiring + data.eol > 0 ? "emphasis" : ""}`}>
              <div className="stat-value">{data.ssl_expiring + data.eol}</div>
              <div className="stat-label">SSL / EOL</div>
            </div>
            <div className={`stat ${data.hardening_drift > 0 ? "emphasis" : ""}`}>
              <div className="stat-value">{data.hardening_drift}</div>
              <div className="stat-label">Härtungs-Drift</div>
            </div>
          </div>

          <Section
            title="Heute"
            note="Priorisierte Warteschlange über alle Kunden"
            action={<Link className="btn secondary sm" href="/reviews">Review-Queue</Link>}
          >
            {data.queue.length === 0 && <p className="muted" style={{ margin: 0 }}>Nichts Offenes – Flotte ist ruhig.</p>}
            {data.queue.map((item, i) => (
              <Link href={item.href} key={`${item.kind}-${item.site_id}-${i}`} className="event-item" style={{ display: "block", textDecoration: "none", color: "inherit" }}>
                <div className="list-card-top">
                  <div>
                    <strong>{item.title}</strong>
                    <div className="cell-sub">{item.detail}</div>
                  </div>
                  <span className={`badge ${item.severity}`}>{item.kind}</span>
                </div>
              </Link>
            ))}
          </Section>

          {(data.active_jobs || []).length > 0 && (
            <Section title="Laufende Prozesse" note="Fortschritt in Echtzeit">
              <ProcessList
                onCancelled={() => api<Dashboard>("/dashboard").then(setData).catch(() => undefined)}
                items={(data.active_jobs || []).map((j) => ({
                  id: j.id,
                  site_name: j.site_name,
                  ...(j.progress_ui || { percent: 0, title: "Job", label: "…" }),
                }))}
              />
            </Section>
          )}

          <Section title="Live-Events" note="Neueste Meldungen" action={<Link className="btn secondary sm" href="/activity">WordPress-Log</Link>}>
            {data.recent_events.length === 0 && <p className="muted" style={{ margin: 0 }}>Noch keine Events.</p>}
            {data.recent_events.map((ev) => (
              <div className="event-item" key={ev.id}>
                <div className="list-card-top">
                  <div>
                    <strong>{ev.title}</strong>
                    <div className="cell-sub">{ev.type} · {new Date(ev.occurred_at).toLocaleString("de-DE")}</div>
                  </div>
                  <span className={`badge ${ev.severity}`}>{ev.severity}</span>
                </div>
              </div>
            ))}
          </Section>
        </>
      )}
    </Shell>
  );
}
