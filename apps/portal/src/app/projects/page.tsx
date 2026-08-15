"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Shell } from "@/components/Shell";
import { InstallWizard, InstallInfo } from "@/components/InstallWizard";
import { ProjectOnboardingWizard } from "@/components/ProjectOnboardingWizard";
import { Drawer, Empty, Flash, PageHeader } from "@/components/ui";
import { api, API_URL, downloadPlugin, downloadSiteBackup } from "@/lib/api";

type Client = { id: string; name: string; email?: string };
type Project = {
  id: string;
  name: string;
  monthly_budget_cents: number;
  maintenance_tier?: string | null;
  currency: string;
  active: boolean;
  scope?: Record<string, boolean | number>;
  client?: Client;
  sites?: Array<{ id: string; name: string; status: string; url?: string; onboarding_status?: string }>;
};

export default function ProjectsPage() {
  const [projects, setProjects] = useState<Project[]>([]);
  const [msg, setMsg] = useState("");
  const [msgTone, setMsgTone] = useState<"info" | "error" | "ok">("info");
  const [install, setInstall] = useState<InstallInfo | null>(null);
  const [wizardOpen, setWizardOpen] = useState(false);

  async function load() {
    const p = await api<{ data: Project[] }>("/projects");
    setProjects(p.data);
  }

  useEffect(() => {
    load().catch((e) => {
      setMsgTone("error");
      setMsg(e.message);
    });
  }, []);

  async function toggleAutoFix(project: Project) {
    const current = Boolean(project.scope?.auto_apply_safe_updates);
    await api(`/projects/${project.id}`, {
      method: "PUT",
      body: JSON.stringify({
        scope: { ...project.scope, auto_apply_safe_updates: !current },
      }),
    });
    await load();
  }

  async function removeProject(project: Project) {
    const site = project.sites?.[0];
    if (site && confirm(`Letzten Full-Backup von „${site.name}“ vor dem Löschen herunterladen?`)) {
      try {
        setMsgTone("info");
        setMsg("Lade letzten Backup-Stand…");
        await downloadSiteBackup(site.id, "latest");
      } catch (e) {
        if (!confirm(`${e instanceof Error ? e.message : "Download fehlgeschlagen"}\n\nTrotzdem löschen?`)) {
          setMsg("");
          return;
        }
      }
    }

    if (
      !confirm(
        `„${project.name}“ vollständig löschen?\n\nPortal-Daten + Staging/Backups auf WordPress.\nDie Live-Website bleibt erhalten.`
      )
    ) {
      return;
    }

    setMsgTone("info");
    setMsg("Lösche Projekt…");
    await api(`/projects/${project.id}`, { method: "DELETE" });
    setInstall(null);
    setMsgTone("ok");
    setMsg("Projekt vollständig gelöscht");
    await load();
  }

  async function reconnectProject(project: Project) {
    const res = await api<{ install: InstallInfo }>(`/projects/${project.id}/reconnect`, {
      method: "POST",
      body: JSON.stringify({}),
    });
    setInstall(res.install);
    setMsgTone("info");
    setMsg("Neue Verbindung bereit – Pairing-Code verwenden.");
    try {
      await downloadPlugin();
    } catch {
      /* optional */
    }
    await load();
  }

  function tierLabel(t?: string | null) {
    return ({ "1": "1. Stufe", "2": "2. Stufe", "3": "3. Stufe", custom: "Custom" } as Record<string, string>)[t || ""] || "–";
  }

  return (
    <Shell>
      <PageHeader
        title="Projekte"
        subtitle="Kunden-Onboarding, Wartungsstufen und verknüpfte Sites an einem Ort."
        actions={
          <button className="btn" type="button" onClick={() => setWizardOpen(true)}>
            Neues Projekt
          </button>
        }
      />

      <Flash tone={msgTone}>{msg}</Flash>
      {install && <div style={{ marginBottom: 16 }}><InstallWizard install={install} /></div>}

      <div className="surface">
        {projects.length === 0 ? (
          <Empty
            title="Noch keine Projekte"
            text="Starte mit „Neues Projekt“ – Impressum, Kunde, Stufe und Setup laufen im Wizard."
          />
        ) : (
          <table className="table">
            <thead>
              <tr>
                <th>Projekt</th>
                <th>Kunde</th>
                <th>Stufe</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {projects.map((p) => {
                const site = p.sites?.[0];
                return (
                  <tr key={p.id}>
                    <td>
                      <div className="cell-title">{p.name}</div>
                      <div className="cell-sub">
                        {(p.sites || []).length === 0 && "Keine Site"}
                        {(p.sites || []).map((st, i) => (
                          <span key={st.id}>
                            {i > 0 && " · "}
                            <Link href={`/sites/${st.id}`} style={{ color: "var(--accent-2)" }}>{st.name}</Link>
                            {" "}
                            <span className={`badge ${st.status}`}>{st.status}</span>
                          </span>
                        ))}
                      </div>
                    </td>
                    <td>
                      <div>{p.client?.name || "–"}</div>
                      {p.client?.email && <div className="cell-sub">{p.client.email}</div>}
                    </td>
                    <td>
                      <div>{tierLabel(p.maintenance_tier)}</div>
                      <div className="cell-sub">
                        {(p.monthly_budget_cents / 100).toFixed(0)} {p.currency}/Mo
                      </div>
                    </td>
                    <td>
                      <div className="action-menu">
                        <button
                          className="btn secondary sm"
                          type="button"
                          onClick={async () => {
                            const token = localStorage.getItem("wwc_token");
                            const res = await fetch(`${API_URL}/api/projects/${p.id}/report`, {
                              headers: { Authorization: `Bearer ${token || ""}` },
                            });
                            if (!res.ok) return;
                            const blob = await res.blob();
                            const url = URL.createObjectURL(blob);
                            window.open(url, "_blank");
                          }}
                        >
                          Bericht
                        </button>
                        <button className="btn secondary sm" type="button" onClick={() => toggleAutoFix(p)}>
                          Auto-Fix {p.scope?.auto_apply_safe_updates ? "an" : "aus"}
                        </button>
                        <button className="btn secondary sm" type="button" onClick={() => reconnectProject(p)}>
                          Verbinden
                        </button>
                        <button className="btn danger sm" type="button" onClick={() => removeProject(p)}>
                          Löschen
                        </button>
                      </div>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        )}
      </div>

      <Drawer
        open={wizardOpen}
        title="Neues Projekt"
        subtitle="Impressum prüfen, Kunde anlegen, Stufe wählen – danach Plugin & Setup."
        onClose={() => setWizardOpen(false)}
      >
        <ProjectOnboardingWizard
          embedded
          onDone={() => {
            load().catch(() => undefined);
          }}
        />
      </Drawer>
    </Shell>
  );
}
