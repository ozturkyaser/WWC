"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { Shell } from "@/components/Shell";
import { ProcessList } from "@/components/ProcessBar";
import { Flash, PageHeader, Section } from "@/components/ui";
import { api } from "@/lib/api";

type Dashboard = {
  sites_total: number;
  sites_online: number;
  sites_offline: number;
  failed_logins_24h: number;
  open_vulnerabilities: number;
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
        title="Übersicht"
        subtitle="Was gerade Aufmerksamkeit braucht – und der Live-Pulse deiner Sites."
        actions={<Link className="btn secondary" href="/projects">Neues Projekt</Link>}
      />
      <Flash tone="error">{error}</Flash>

      {data && (
        <>
          <div className="grid stats" style={{ marginBottom: 28 }}>
            <div className={`stat ${data.open_vulnerabilities > 0 ? "alert" : ""}`}>
              <div className="stat-value">{data.open_vulnerabilities}</div>
              <div className="stat-label">Offene Schwachstellen</div>
            </div>
            <div className={`stat ${data.sites_offline > 0 ? "emphasis" : ""}`}>
              <div className="stat-value">{data.sites_offline}</div>
              <div className="stat-label">Offline / pending</div>
            </div>
            <div className="stat">
              <div className="stat-value">{data.sites_online}/{data.sites_total}</div>
              <div className="stat-label">Sites online</div>
            </div>
            <div className="stat">
              <div className="stat-value">{data.failed_logins_24h}</div>
              <div className="stat-label">Fehl-Logins (24h)</div>
            </div>
          </div>

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

          <Section
            title="Live-Events"
            note="Neueste Meldungen aus verbundenen Sites"
            action={<Link className="btn secondary sm" href="/sites">Alle Sites</Link>}
          >
            {data.recent_events.length === 0 && (
              <p className="muted" style={{ margin: 0 }}>Noch keine Events.</p>
            )}
            {data.recent_events.map((ev) => (
              <div className="event-item" key={ev.id}>
                <div className="list-card-top">
                  <div>
                    <strong>{ev.title}</strong>
                    <div className="cell-sub">
                      {ev.type} · {new Date(ev.occurred_at).toLocaleString("de-DE")}
                    </div>
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
