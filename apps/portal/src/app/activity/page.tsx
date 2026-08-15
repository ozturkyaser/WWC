"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { Shell } from "@/components/Shell";
import { Empty, Flash, PageHeader } from "@/components/ui";
import { api } from "@/lib/api";

type Payload = {
  user_login?: string | null;
  user_email?: string | null;
  roles?: string[];
  ip?: string | null;
  target_login?: string | null;
  plugin?: string;
  theme?: string;
  option?: string;
  monitor?: { flags?: string[]; score?: number };
};

type EventRow = {
  id: string;
  type: string;
  title: string;
  severity: string;
  occurred_at: string;
  payload?: Payload | null;
  site?: { id: string; name: string; url?: string } | null;
};

export default function ActivityPage() {
  const [rows, setRows] = useState<EventRow[]>([]);
  const [error, setError] = useState("");
  const [suspicious, setSuspicious] = useState(true);
  const [q, setQ] = useState("");

  function load() {
    const params = new URLSearchParams();
    if (suspicious) params.set("suspicious", "1");
    if (q.trim()) params.set("q", q.trim());
    api<{ data: EventRow[] }>(`/activity?${params}`)
      .then((r) => setRows(r.data))
      .catch((e) => setError(e.message));
  }

  useEffect(() => {
    load();
    const t = setInterval(load, 12000);
    return () => clearInterval(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [suspicious]);

  return (
    <Shell>
      <PageHeader
        title="WordPress-Aktivität"
        subtitle="Wer hat was auf den Sites gemacht. Die Wache warnt bei Verdacht und kann Aktionen stoppen."
      />
      <Flash tone="error">{error}</Flash>
      <div className="row" style={{ marginBottom: 14, gap: 10, flexWrap: "wrap" }}>
        <label className="row" style={{ gap: 8, alignItems: "center" }}>
          <input type="checkbox" checked={suspicious} onChange={(e) => setSuspicious(e.target.checked)} />
          Nur verdächtig
        </label>
        <input
          placeholder="Suche Titel oder Typ…"
          value={q}
          onChange={(e) => setQ(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === "Enter") load();
          }}
          style={{ minWidth: 220 }}
        />
        <button className="btn secondary sm" type="button" onClick={load}>Aktualisieren</button>
      </div>
      <div className="surface">
        {rows.length === 0 ? (
          <Empty title="Keine Einträge" text="Sobald der Agent 0.6+ gekoppelt ist, erscheinen Anmeldungen, Benutzer, Plugins und Inhalte hier." />
        ) : (
          <table className="table">
            <thead>
              <tr>
                <th>Zeit</th>
                <th>Site</th>
                <th>Wer</th>
                <th>Aktion</th>
                <th>IP</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((ev) => {
                const p = ev.payload || {};
                const flags = p.monitor?.flags || [];
                return (
                  <tr key={ev.id}>
                    <td className="cell-sub">{new Date(ev.occurred_at).toLocaleString("de-DE")}</td>
                    <td>
                      {ev.site ? <Link href={`/sites/${ev.site.id}?tab=activity`}>{ev.site.name}</Link> : "–"}
                    </td>
                    <td>
                      <div>{p.user_login || "System / unbekannt"}</div>
                      {p.user_email && <div className="cell-sub">{p.user_email}</div>}
                    </td>
                    <td>
                      <div className="row" style={{ gap: 8, alignItems: "center" }}>
                        <strong>{ev.title}</strong>
                        <span className={`badge ${ev.severity}`}>{ev.severity}</span>
                      </div>
                      <div className="cell-sub">{ev.type}{p.target_login ? ` · Ziel ${p.target_login}` : ""}</div>
                      {flags.length > 0 && (
                        <div className="cell-sub">Wache: {flags.join(", ")}</div>
                      )}
                    </td>
                    <td className="cell-sub">{p.ip || "–"}</td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        )}
      </div>
    </Shell>
  );
}
