"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { Shell } from "@/components/Shell";
import { Empty, Flash, PageHeader } from "@/components/ui";
import { api } from "@/lib/api";

type SupportSite = {
  id: string;
  name: string;
  url: string;
  status: string;
  wp_version?: string;
  php_version?: string;
  agent_version?: string;
  support_granted_at?: string | null;
  support_contact?: string | null;
  support_note?: string | null;
  access_mode?: string | null;
  client?: { id: string; name: string } | null;
};

export default function SupportPage() {
  const [sites, setSites] = useState<SupportSite[]>([]);
  const [error, setError] = useState("");

  async function load() {
    const res = await api<{ data: SupportSite[] }>("/support-sites");
    setSites(res.data);
  }

  useEffect(() => {
    load().catch((e) => setError(e instanceof Error ? e.message : "Fehler"));
  }, []);

  return (
    <Shell>
      <PageHeader
        title="Support"
        subtitle="Kunden haben den Agenten installiert und den Zugang freigegeben – ohne Pairing-Code."
      />
      <Flash tone="error">{error}</Flash>
      <div className="surface">
        {sites.length === 0 ? (
          <Empty
            title="Keine offenen Freigaben"
            text="Sobald ein Kunde unter Einstellungen → WWC Agent auf „Für WWC-Support freigeben“ klickt, erscheint die Site hier. Danach kannst du sie wie jede andere Site steuern."
          />
        ) : (
          <table className="table">
            <thead>
              <tr>
                <th>Site</th>
                <th>Freigabe</th>
                <th>Kontakt</th>
                <th>Stack</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {sites.map((s) => (
                <tr key={s.id}>
                  <td>
                    <Link href={`/sites/${s.id}`} className="cell-title" style={{ color: "var(--text)" }}>
                      {s.name}
                    </Link>
                    <div className="cell-sub">{s.url}</div>
                  </td>
                  <td>
                    <span className="badge online">offen</span>
                    <div className="cell-sub">
                      {s.support_granted_at
                        ? new Date(s.support_granted_at).toLocaleString("de-DE")
                        : "–"}
                    </div>
                  </td>
                  <td>
                    <div>{s.support_contact || "–"}</div>
                    {s.support_note && <div className="cell-sub">{s.support_note}</div>}
                  </td>
                  <td className="muted">
                    WP {s.wp_version || "–"} · PHP {s.php_version || "–"} · Agent {s.agent_version || "–"}
                  </td>
                  <td>
                    <Link className="btn secondary sm" href={`/sites/${s.id}`}>
                      Öffnen
                    </Link>
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
