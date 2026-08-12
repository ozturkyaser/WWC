"use client";

import { FormEvent, useEffect, useState } from "react";
import Link from "next/link";
import { Shell } from "@/components/Shell";
import { InstallWizard, InstallInfo } from "@/components/InstallWizard";
import { Drawer, Empty, Flash, PageHeader } from "@/components/ui";
import { api, downloadPlugin } from "@/lib/api";

type Site = {
  id: string;
  name: string;
  url: string;
  status: string;
  wp_version?: string;
  php_version?: string;
  open_findings_count?: number;
};

export default function SitesPage() {
  const [sites, setSites] = useState<Site[]>([]);
  const [name, setName] = useState("");
  const [url, setUrl] = useState("https://");
  const [install, setInstall] = useState<InstallInfo | null>(null);
  const [error, setError] = useState("");
  const [open, setOpen] = useState(false);

  async function load() {
    const res = await api<{ data: Site[] }>("/sites");
    setSites(res.data);
  }

  useEffect(() => {
    load().catch((e) => setError(e.message));
  }, []);

  async function createSite(e: FormEvent) {
    e.preventDefault();
    setError("");
    setInstall(null);
    try {
      const res = await api<{ data: Site; install: InstallInfo }>("/sites", {
        method: "POST",
        body: JSON.stringify({ name, url }),
      });
      setInstall(res.install);
      setName("");
      setUrl("https://");
      setOpen(false);
      await load();
      try {
        await downloadPlugin();
      } catch {
        /* retry via wizard */
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : "Fehler");
    }
  }

  return (
    <Shell>
      <PageHeader
        title="Sites"
        subtitle="Verbundene WordPress-Installationen – Status, Security und Wartung."
        actions={
          <button className="btn" type="button" onClick={() => setOpen(true)}>
            Site verbinden
          </button>
        }
      />

      <Flash tone="error">{error}</Flash>
      {install && <div style={{ marginBottom: 16 }}><InstallWizard install={install} /></div>}

      <div className="surface">
        {sites.length === 0 ? (
          <Empty title="Keine Sites" text="Verbinde eine WordPress-Site oder lege ein Projekt über den Wizard an." />
        ) : (
          <table className="table">
            <thead>
              <tr>
                <th>Site</th>
                <th>Status</th>
                <th>Stack</th>
                <th>Vulns</th>
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
                    <span className="meta-chip">
                      <span className={`dot ${s.status}`} />
                      <span className={`badge ${s.status}`}>{s.status}</span>
                    </span>
                  </td>
                  <td className="muted">WP {s.wp_version || "–"} · PHP {s.php_version || "–"}</td>
                  <td>
                    <span className={`badge ${(s.open_findings_count || 0) > 0 ? "high" : "online"}`}>
                      {s.open_findings_count ?? 0}
                    </span>
                  </td>
                  <td>
                    <div className="action-menu">
                      <Link className="btn secondary sm" href={`/sites/${s.id}`}>Öffnen</Link>
                      <button
                        className="btn secondary sm"
                        type="button"
                        onClick={async () => {
                          const res = await api<{ install: InstallInfo }>(`/sites/${s.id}/reconnect`, { method: "POST" });
                          setInstall(res.install);
                          try { await downloadPlugin(); } catch { /* ignore */ }
                          await load();
                        }}
                      >
                        Verbinden
                      </button>
                      <button
                        className="btn danger sm"
                        type="button"
                        onClick={async () => {
                          if (!confirm(`Site „${s.name}“ löschen? Staging & Backups werden entfernt.`)) return;
                          await api(`/sites/${s.id}`, { method: "DELETE" });
                          setInstall(null);
                          await load();
                        }}
                      >
                        Löschen
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      <Drawer
        open={open}
        title="Site verbinden"
        subtitle="Name + URL – danach Plugin und Pairing-Code."
        onClose={() => setOpen(false)}
      >
        <form onSubmit={createSite}>
          <div className="field">
            <label>Name</label>
            <input value={name} onChange={(e) => setName(e.target.value)} required />
          </div>
          <div className="field">
            <label>URL</label>
            <input value={url} onChange={(e) => setUrl(e.target.value)} required />
          </div>
          {error && <p className="error">{error}</p>}
          <button className="btn" type="submit">Anlegen & Plugin</button>
        </form>
      </Drawer>
    </Shell>
  );
}
