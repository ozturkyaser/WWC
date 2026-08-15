"use client";

import { useEffect, useState } from "react";
import { Shell } from "@/components/Shell";
import { Empty, Flash, PageHeader } from "@/components/ui";
import { api } from "@/lib/api";

type Log = {
  id: string;
  action: string;
  site_id?: string | null;
  ip?: string | null;
  created_at: string;
  meta?: Record<string, unknown>;
};

export default function AuditPage() {
  const [logs, setLogs] = useState<Log[]>([]);
  const [error, setError] = useState("");

  useEffect(() => {
    api<{ data: Log[] }>("/audit-logs")
      .then((r) => setLogs(r.data))
      .catch((e) => setError(e.message));
  }, []);

  return (
    <Shell>
      <PageHeader title="Protokoll" subtitle="Wer hat was ausgelöst – intern, nicht für den Kunden." />
      <Flash tone="error">{error}</Flash>
      <div className="surface">
        {logs.length === 0 ? (
          <Empty title="Noch keine Einträge" text="Aktionen wie Login, Updates und Einladungen erscheinen hier." />
        ) : (
          <table className="table">
            <thead><tr><th>Zeit</th><th>Aktion</th><th>IP</th></tr></thead>
            <tbody>
              {logs.map((l) => (
                <tr key={l.id}>
                  <td>{new Date(l.created_at).toLocaleString("de-DE")}</td>
                  <td>
                    <div>{l.action}</div>
                    {l.meta && Object.keys(l.meta).length > 0 && (
                      <div className="cell-sub">{JSON.stringify(l.meta)}</div>
                    )}
                  </td>
                  <td className="cell-sub">{l.ip || "–"}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </Shell>
  );
}
