"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { Shell } from "@/components/Shell";
import { Empty, Flash, PageHeader } from "@/components/ui";
import { api } from "@/lib/api";

type Run = {
  id: string;
  status: string;
  trigger?: string;
  ai_summary?: string | null;
  error?: string | null;
  started_at?: string | null;
  site?: { id: string; name: string; url: string };
};

export default function ReviewsPage() {
  const [runs, setRuns] = useState<Run[]>([]);
  const [msg, setMsg] = useState("");
  const [tone, setTone] = useState<"info" | "ok" | "error">("info");
  const [busy, setBusy] = useState<string | null>(null);

  async function load() {
    const res = await api<{ data: Run[] }>("/reviews");
    setRuns(res.data);
  }

  useEffect(() => {
    load().catch((e) => {
      setTone("error");
      setMsg(e.message);
    });
  }, []);

  async function act(id: string, action: "approve" | "dismiss") {
    setBusy(id);
    try {
      await api(`/reviews/${id}/${action}`, { method: "POST" });
      setTone("ok");
      setMsg(action === "approve" ? "Ausführung gestartet" : "Review verworfen");
      await load();
    } catch (e) {
      setTone("error");
      setMsg(e instanceof Error ? e.message : "Fehler");
    } finally {
      setBusy(null);
    }
  }

  return (
    <Shell>
      <PageHeader title="Review-Queue" subtitle="KI-Läufe, die ein Techniker freigeben oder verwerfen muss." />
      <Flash tone={tone}>{msg}</Flash>
      <div className="surface">
        {runs.length === 0 ? (
          <Empty title="Keine offenen Reviews" text="Neue Wartungsläufe mit needs_review erscheinen hier." />
        ) : (
          <table className="table">
            <thead>
              <tr>
                <th>Site</th>
                <th>Status</th>
                <th>Zusammenfassung</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {runs.map((r) => (
                <tr key={r.id}>
                  <td>
                    {r.site ? <Link href={`/sites/${r.site.id}`}>{r.site.name}</Link> : "–"}
                    <div className="cell-sub">{r.started_at ? new Date(r.started_at).toLocaleString("de-DE") : ""}</div>
                  </td>
                  <td><span className={`badge ${r.status === "failed" ? "error" : "warn"}`}>{r.status}</span></td>
                  <td><div className="cell-sub">{r.ai_summary || r.error || "–"}</div></td>
                  <td>
                    <div className="action-menu">
                      <button className="btn sm" disabled={busy === r.id} type="button" onClick={() => act(r.id, "approve")}>
                        Freigeben
                      </button>
                      <button className="btn secondary sm" disabled={busy === r.id} type="button" onClick={() => act(r.id, "dismiss")}>
                        Verwerfen
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </Shell>
  );
}
