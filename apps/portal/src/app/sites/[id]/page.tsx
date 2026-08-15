"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { useParams } from "next/navigation";
import { Shell } from "@/components/Shell";
import { InstallWizard, InstallInfo } from "@/components/InstallWizard";
import { ProcessBar, ProcessList } from "@/components/ProcessBar";
import { Flash, PageHeader, Tabs } from "@/components/ui";
import { api, downloadPlugin, downloadSiteBackup } from "@/lib/api";

type Backup = {
  id: string;
  type: string;
  label?: string;
  created_at: string;
  size_bytes?: number;
  offsite?: boolean;
};

type ServerBackup = {
  id: string;
  backup_id: string;
  type: string;
  label?: string | null;
  status: string;
  size_bytes?: number;
  backup_created_at?: string | null;
  uploaded_at?: string | null;
};

type BackupSchedule = {
  enabled?: boolean;
  time?: string;
  weekly_full_day?: number;
  incremental_daily?: boolean;
};

type DevClone = {
  status: string;
  url?: string | null;
  backup_id?: string | null;
  php_image?: string | null;
  admin_user?: string | null;
  admin_pass?: string | null;
  error?: string | null;
  built_at?: string | null;
};

type Staging = {
  exists: boolean;
  url?: string | null;
  meta?: { last_dry_run?: { command?: string; at?: string } } | null;
};

type StagingPortal = {
  exists: boolean;
  portal_url?: string | null;
  preview_url?: string | null;
  admin_login_url?: string | null;
  access?: { username?: string; password?: string | null } | null;
};

type ProgressLogEntry = { at?: string; message: string; percent?: number };
type JobRow = {
  id: string;
  command?: string;
  status?: string;
  error?: string | null;
  payload?: { slug?: string; mode?: string; items?: Array<{ type?: string; slug?: string }> } | null;
  result?: {
    ok?: boolean;
    error?: string;
    results?: Array<{ type?: string; slug?: string; ok?: boolean; error?: string | null }>;
  } | null;
  progress_ui?: {
    percent: number;
    label?: string;
    title?: string;
    status?: string;
    outcome?: string | null;
    error?: string | null;
    items?: Array<{ type?: string; slug?: string; ok?: boolean; error?: string | null }>;
    log?: ProgressLogEntry[];
  };
};
type ActiveJob = JobRow;

type SiteDetail = {
  data: {
    id: string;
    name: string;
    url: string;
    status: string;
    wp_version?: string;
    php_version?: string;
    agent_version?: string;
    inventory?: {
      plugins?: Array<{ name: string; slug: string; version: string; update_available?: string; active: boolean }>;
      themes?: Array<{ name: string; slug: string; version: string; update_available?: string }>;
      core?: { version: string; update_available?: string };
    };
    health?: { backups?: Backup[]; staging?: Staging };
    backup_schedule?: BackupSchedule | null;
  };
  server_backups?: ServerBackup[];
  dev_clone?: DevClone | null;
  staging_portal?: StagingPortal;
  events: Array<{ id: string; type: string; title: string; severity: string; occurred_at: string }>;
  jobs: JobRow[];
  active_jobs?: ActiveJob[];
  prioritized_updates?: Array<{
    type: string;
    slug: string;
    name: string;
    current?: string;
    update_to?: string;
    priority_score: number;
    security?: {
      severity?: string;
      is_exploited?: boolean;
      cvss?: number;
      title?: string;
      fixed_in?: string;
      url?: string;
    } | null;
  }>;
  agent_release?: {
    latest?: string;
    update_available?: boolean;
  };
  maintenance?: {
    enabled?: boolean;
    cadence?: string;
    auto_apply?: boolean;
    last_run_at?: string | null;
    next_run_at?: string | null;
    latest_run?: MaintenanceRun | null;
  };
};

type MaintenanceRun = {
  id: string;
  status: string;
  trigger?: string;
  ai_summary?: string | null;
  technician_notes?: string | null;
  error?: string | null;
  started_at?: string | null;
  finished_at?: string | null;
  audit?: {
    scores?: { risk?: number; health?: number };
    plugins?: { total?: number; active?: number; inactive?: number };
    unused_plugins?: Array<{ slug: string; name: string; reason?: string }>;
    unused_themes?: Array<{ slug: string; name: string; reason?: string }>;
    security_findings?: Array<{ title: string; severity?: string; slug?: string }>;
    failed_logins_24h?: number;
  } | null;
  plan?: {
    updates?: Array<{ type: string; slug: string; name?: string; from?: string; to?: string; priority_score?: number }>;
    recommendations?: Array<{ name?: string; slug?: string; reason?: string; action?: string }>;
  } | null;
};

type ScanReport = {
  ok?: boolean;
  scanned_at?: string;
  included?: { files?: number; bytes?: number };
  excluded?: { files?: number; bytes?: number };
  auto_skipped?: { files?: number; bytes?: number; groups?: Array<{ path: string; size_bytes: number }> };
  db_bytes?: number;
  estimated_backup_bytes?: number;
  top_files?: Array<{ path: string; size_bytes: number; status: string }>;
  top_dirs?: Array<{ path: string; size_bytes: number }>;
  settings?: { max_file_mb?: number; excludes?: string[] };
};

function formatBytes(n?: number) {
  if (!n) return "–";
  if (n < 1024) return `${n} B`;
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`;
  if (n < 1024 * 1024 * 1024) return `${(n / (1024 * 1024)).toFixed(1)} MB`;
  return `${(n / (1024 * 1024 * 1024)).toFixed(2)} GB`;
}

export default function SiteDetailPage() {
  const params = useParams<{ id: string }>();
  const [detail, setDetail] = useState<SiteDetail | null>(null);
  const [install, setInstall] = useState<InstallInfo | null>(null);
  const [msg, setMsg] = useState("");
  const [msgTone, setMsgTone] = useState<"info" | "error" | "ok">("info");
  const [busy, setBusy] = useState(false);
  const [tab, setTab] = useState("overview");
  const [selectedUpdates, setSelectedUpdates] = useState<Set<string>>(new Set());
  const [maintBusy, setMaintBusy] = useState(false);
  const [scanExcludes, setScanExcludes] = useState<Set<string>>(new Set());
  const [saveScanSettings, setSaveScanSettings] = useState(true);
  const [scanInitId, setScanInitId] = useState("");

  const latestScanJob = (detail?.jobs || []).find(
    (j) => j.command === "backup_scan" && j.status === "completed"
  );
  const scanReport = latestScanJob ? (latestScanJob.result as unknown as ScanReport | null) : null;

  useEffect(() => {
    // Pre-select files the current settings would exclude anyway
    if (latestScanJob && latestScanJob.id !== scanInitId && scanReport?.top_files) {
      setScanInitId(latestScanJob.id);
      setScanExcludes(
        new Set(scanReport.top_files.filter((f) => f.status !== "included").map((f) => f.path))
      );
    }
  }, [latestScanJob?.id]); // eslint-disable-line react-hooks/exhaustive-deps

  async function load() {
    const res = await api<SiteDetail>(`/sites/${params.id}`);
    setDetail(res);
  }

  useEffect(() => {
    load().catch((e) => {
      setMsgTone("error");
      setMsg(e.message);
    });
    const hasUpdateJobs = (detail?.active_jobs || []).some((j) =>
      /update|dry-run|multi/i.test(String(j.command || j.progress_ui?.title || ""))
    );
    const interval = tab === "updates" || hasUpdateJobs ? 2500 : 6000;
    const t = setInterval(() => load().catch(() => undefined), interval);
    return () => clearInterval(t);
  }, [params.id, tab, detail?.active_jobs?.length]);

  async function run(command: string, payload: Record<string, unknown> = {}) {
    setBusy(true);
    setMsgTone("info");
    setMsg("");
    try {
      await api(`/sites/${params.id}/commands`, {
        method: "POST",
        body: JSON.stringify({ command, payload }),
      });
      setMsg(`„${command}“ gestartet`);
      if (command.includes("update") || command.startsWith("staging_update")) {
        setTab("updates");
      }
      await load();
    } catch (e) {
      setMsgTone("error");
      setMsg(e instanceof Error ? e.message : "Fehler");
    } finally {
      setBusy(false);
    }
  }

  function updateKey(type: string, slug: string) {
    return `${type}:${slug}`;
  }

  function toggleUpdate(type: string, slug: string) {
    const key = updateKey(type, slug);
    setSelectedUpdates((prev) => {
      const next = new Set(prev);
      if (next.has(key)) next.delete(key);
      else next.add(key);
      return next;
    });
  }

  async function runSelected(mode: "staging" | "live") {
    const items = [...selectedUpdates].map((key) => {
      const [type, ...rest] = key.split(":");
      return { type, slug: rest.join(":") };
    });
    if (items.length === 0) {
      setMsgTone("error");
      setMsg("Bitte mindestens ein Update auswählen");
      return;
    }
    if (mode === "staging" && !detail?.data?.health?.staging?.exists) {
      setMsgTone("error");
      setMsg("Zuerst Development/Staging anlegen");
      return;
    }
    await run("update_batch", { mode, items });
    setSelectedUpdates(new Set());
  }

  async function saveBackupSchedule(patch: BackupSchedule) {
    setBusy(true);
    try {
      const current = detail?.data?.backup_schedule || {};
      const res = await api<{ data: SiteDetail["data"] }>(`/sites/${params.id}/backup-schedule`, {
        method: "PUT",
        body: JSON.stringify({ enabled: current.enabled ?? false, ...current, ...patch }),
      });
      setDetail((d) => (d ? { ...d, data: res.data } : d));
      setMsgTone("ok");
      setMsg("Backup-Zeitplan gespeichert");
    } catch (e) {
      setMsgTone("error");
      setMsg(e instanceof Error ? e.message : "Fehler");
    } finally {
      setBusy(false);
    }
  }

  async function saveMaintenance(patch: Record<string, unknown>) {
    setMaintBusy(true);
    try {
      const res = await api<{ agent: SiteDetail["maintenance"] }>(`/sites/${params.id}/maintenance`, {
        method: "PUT",
        body: JSON.stringify(patch),
      });
      setDetail((d) => (d ? { ...d, maintenance: res.agent } : d));
      setMsgTone("ok");
      setMsg("Wartungs-Agent gespeichert");
    } catch (e) {
      setMsgTone("error");
      setMsg(e instanceof Error ? e.message : "Fehler");
    } finally {
      setMaintBusy(false);
    }
  }

  async function runMaintenance(execute: boolean) {
    setMaintBusy(true);
    setMsgTone("info");
    setMsg(execute ? "Wartungs-Agent: Audit + Dry-Run/Live…" : "Wartungs-Agent: Audit läuft…");
    try {
      const res = await api<{ data: MaintenanceRun; agent: SiteDetail["maintenance"] }>(
        `/sites/${params.id}/maintenance/run`,
        { method: "POST", body: JSON.stringify({ execute }) }
      );
      setDetail((d) => (d ? { ...d, maintenance: res.agent } : d));
      setTab("maintenance");
      setMsgTone(res.data.status === "failed" ? "error" : "ok");
      setMsg(`Audit ${res.data.status}${res.data.ai_summary ? " – Bericht bereit" : ""}`);
      await load();
    } catch (e) {
      setMsgTone("error");
      setMsg(e instanceof Error ? e.message : "Fehler");
    } finally {
      setMaintBusy(false);
    }
  }

  async function executeMaintenancePlan(runId: string) {
    setMaintBusy(true);
    try {
      await api(`/sites/${params.id}/maintenance/runs/${runId}/execute`, { method: "POST" });
      setMsgTone("ok");
      setMsg("Dry-Run → Live gestartet");
      await load();
    } catch (e) {
      setMsgTone("error");
      setMsg(e instanceof Error ? e.message : "Fehler");
    } finally {
      setMaintBusy(false);
    }
  }

  async function newPairing() {
    const res = await api<{ install: InstallInfo }>(`/sites/${params.id}/reconnect`, { method: "POST" });
    setInstall(res.install);
    try {
      await downloadPlugin();
    } catch {
      /* retry */
    }
    await load();
  }

  async function scan() {
    setBusy(true);
    try {
      await api(`/security/sites/${params.id}/scan`, { method: "POST" });
      setMsgTone("ok");
      setMsg("Scan abgeschlossen");
      await load();
    } catch (e) {
      setMsgTone("error");
      setMsg(e instanceof Error ? e.message : "Fehler");
    } finally {
      setBusy(false);
    }
  }

  if (!detail) {
    return <Shell><p className="muted">Lade Site…</p></Shell>;
  }

  const site = detail.data;
  const stagingPortal = detail.staging_portal;
  const plugins = site.inventory?.plugins || [];
  const themes = site.inventory?.themes || [];
  // Backups: Server-Datenbank (site_backups) ist die verlaessliche Quelle,
  // Agent-Heartbeat liefert Zusatzinfos und rein lokale Backups.
  const agentBackups = site.health?.backups || [];
  const serverBackups = detail.server_backups || [];
  const backups: Backup[] = (() => {
    const byId = new Map<string, Backup>();
    for (const sb of serverBackups) {
      if (sb.status !== "stored") continue;
      byId.set(sb.backup_id, {
        id: sb.backup_id,
        type: sb.type,
        label: sb.label || undefined,
        created_at: sb.backup_created_at || sb.uploaded_at || "",
        size_bytes: sb.size_bytes,
        offsite: true,
      });
    }
    for (const b of agentBackups) {
      const existing = byId.get(b.id);
      byId.set(b.id, { ...b, offsite: b.offsite || Boolean(existing?.offsite) });
    }
    return [...byId.values()].sort((a, b) => (b.created_at || "").localeCompare(a.created_at || ""));
  })();
  const staging = site.health?.staging;
  const updates =
    (site.inventory?.core?.update_available ? 1 : 0) +
    plugins.filter((p) => p.update_available).length +
    themes.filter((t) => t.update_available).length;

  const devClone = detail.dev_clone;

  async function devCloneBuild() {
    setBusy(true);
    try {
      await api(`/sites/${params.id}/dev-clone`, { method: "POST" });
      setMsgTone("info");
      setMsg("Dev-Kopie wird gebaut – das dauert einige Minuten…");
      await load();
    } catch (e) {
      setMsgTone("error");
      setMsg(e instanceof Error ? e.message : "Fehler");
    } finally {
      setBusy(false);
    }
  }

  async function devCloneDestroy() {
    setBusy(true);
    try {
      await api(`/sites/${params.id}/dev-clone`, { method: "DELETE" });
      setMsgTone("ok");
      setMsg("Dev-Kopie gelöscht");
      await load();
    } catch (e) {
      setMsgTone("error");
      setMsg(e instanceof Error ? e.message : "Fehler");
    } finally {
      setBusy(false);
    }
  }

  async function grantStagingAdmin() {
    setBusy(true);
    try {
      await api(`/sites/${params.id}/staging/grant-admin`, { method: "POST" });
      setMsgTone("info");
      setMsg("Admin-Zugang für Staging wird erzeugt…");
      await load();
    } catch (e) {
      setMsgTone("error");
      setMsg(e instanceof Error ? e.message : "Fehler");
    } finally {
      setBusy(false);
    }
  }

  return (
    <Shell>
      <PageHeader
        title={site.name}
        subtitle={
          <>
            <a href={site.url} target="_blank" rel="noreferrer" style={{ color: "var(--accent-2)" }}>
              {site.url}
            </a>
          </>
        }
        actions={
          <>
            <Link className="btn ghost-btn sm" href="/sites">← Sites</Link>
            <button className="btn secondary sm" disabled={busy} type="button" onClick={() => run("inventory")}>
              Inventory
            </button>
            <button
              className="btn secondary sm"
              disabled={busy}
              type="button"
              onClick={() => run("self_update")}
              title={
                detail?.agent_release?.update_available
                  ? `Auf ${detail.agent_release.latest} aktualisieren`
                  : "Agent-Update prüfen / erzwingen"
              }
            >
              Agent aktualisieren
              {detail?.agent_release?.update_available ? ` → ${detail.agent_release.latest}` : ""}
            </button>
            <button className="btn secondary sm" disabled={busy} type="button" onClick={newPairing}>
              Neu verbinden
            </button>
          </>
        }
      />

      <div className="meta-row" style={{ marginTop: -12, marginBottom: 20 }}>
        <span className="meta-chip">
          <span className={`dot ${site.status}`} />
          {site.status}
        </span>
        <span className="meta-chip">WP {site.wp_version || "–"}</span>
        <span className="meta-chip">PHP {site.php_version || "–"}</span>
        <span className="meta-chip">
          Agent {site.agent_version || "–"}
          {detail.agent_release?.update_available && detail.agent_release.latest && (
            <span className="badge warn" style={{ marginLeft: 6 }}>→ {detail.agent_release.latest}</span>
          )}
        </span>
        {updates > 0 && <span className="meta-chip"><span className="badge warn">{updates} Updates</span></span>}
      </div>

      <Flash tone={msgTone}>{msg}</Flash>
      {install && <div style={{ marginBottom: 16 }}><InstallWizard install={install} /></div>}

      <Tabs
        value={tab}
        onChange={setTab}
        items={[
          { id: "overview", label: "Übersicht" },
          { id: "maintenance", label: "KI-Wartung" },
          { id: "updates", label: `Updates${updates ? ` (${updates})` : ""}` },
          { id: "backups", label: "Backups" },
          { id: "staging", label: "Development" },
          { id: "activity", label: "Aktivität" },
        ]}
      />

      {tab === "maintenance" && (() => {
        const m = detail.maintenance;
        const run = m?.latest_run;
        const audit = run?.audit;
        const plan = run?.plan;
        return (
          <div className="surface surface-pad">
            <h3 style={{ marginTop: 0, fontSize: "1.05rem" }}>KI-Wartungsagent</h3>
            <p className="muted" style={{ marginTop: 0 }}>
              Prüft die Site wie ein Techniker: Security, genutzte/ungenutzte Plugins & Themes, Updates.
              Bei Bedarf Dry-Run auf Staging, danach Live – nach Plan (täglich/wöchentlich/monatlich).
            </p>

            <div className="grid two" style={{ marginBottom: 16 }}>
              <div>
                <div className="field">
                  <label>Intervall</label>
                  <select
                    value={m?.cadence || "weekly"}
                    disabled={maintBusy}
                    onChange={(e) => saveMaintenance({ cadence: e.target.value })}
                  >
                    <option value="daily">Täglich</option>
                    <option value="weekly">Wöchentlich</option>
                    <option value="monthly">Monatlich</option>
                    <option value="off">Aus</option>
                  </select>
                </div>
                <label className="row" style={{ gap: 8, marginTop: 10, alignItems: "center" }}>
                  <input
                    type="checkbox"
                    checked={!!m?.enabled}
                    disabled={maintBusy}
                    onChange={(e) => saveMaintenance({ enabled: e.target.checked })}
                  />
                  Agent aktiv
                </label>
                <label className="row" style={{ gap: 8, marginTop: 8, alignItems: "center" }}>
                  <input
                    type="checkbox"
                    checked={!!m?.auto_apply}
                    disabled={maintBusy}
                    onChange={(e) => saveMaintenance({ auto_apply: e.target.checked })}
                  />
                  Auto: Dry-Run → bei OK Live-Updates
                </label>
                <p className="muted" style={{ marginTop: 10, fontSize: "0.85rem" }}>
                  Letzter Lauf: {m?.last_run_at ? new Date(m.last_run_at).toLocaleString("de-DE") : "–"}
                  {" · "}
                  Nächster: {m?.next_run_at ? new Date(m.next_run_at).toLocaleString("de-DE") : "–"}
                </p>
              </div>
              <div className="row" style={{ alignItems: "flex-start", gap: 8, flexWrap: "wrap" }}>
                <button className="btn" type="button" disabled={maintBusy || busy} onClick={() => runMaintenance(false)}>
                  Jetzt auditieren
                </button>
                <button
                  className="btn secondary"
                  type="button"
                  disabled={maintBusy || busy}
                  onClick={() => {
                    if (confirm("Audit + Dry-Run und bei Erfolg Live-Updates?")) runMaintenance(true);
                  }}
                >
                  Audit + ausführen
                </button>
                {run && ["planned", "needs_review"].includes(run.status) && (plan?.updates?.length || 0) > 0 && (
                  <button className="btn" type="button" disabled={maintBusy} onClick={() => executeMaintenancePlan(run.id)}>
                    Plan jetzt ausführen
                  </button>
                )}
              </div>
            </div>

            {run && (
              <>
                <div className="meta-row" style={{ marginBottom: 12 }}>
                  <span className="meta-chip">
                    Status <span className={`badge ${run.status === "completed" ? "completed" : run.status === "failed" || run.status === "needs_review" ? "failed" : "pending"}`}>{run.status}</span>
                  </span>
                  {audit?.scores && (
                    <span className="meta-chip">
                      Risiko {audit.scores.risk}/100 · Health {audit.scores.health}
                    </span>
                  )}
                  {audit?.plugins && (
                    <span className="meta-chip">
                      Plugins {audit.plugins.active}/{audit.plugins.total} aktiv
                    </span>
                  )}
                </div>

                {run.ai_summary && (
                  <div className="surface surface-pad" style={{ marginBottom: 14, background: "rgba(0,0,0,0.2)" }}>
                    <h4 style={{ marginTop: 0, fontSize: "0.95rem" }}>Techniker-Bericht</h4>
                    <p style={{ margin: 0, whiteSpace: "pre-wrap", lineHeight: 1.5 }}>{run.ai_summary}</p>
                  </div>
                )}
                {run.error && (
                  <div className="process-error" style={{ marginBottom: 14 }}>{run.error}</div>
                )}
                {run.technician_notes && (
                  <pre className="muted" style={{ whiteSpace: "pre-wrap", fontSize: "0.82rem", marginBottom: 14 }}>
                    {run.technician_notes}
                  </pre>
                )}

                <div className="grid two">
                  <div>
                    <h4 style={{ fontSize: "0.95rem" }}>Geplante Updates</h4>
                    {(plan?.updates || []).length === 0 && <p className="muted">Keine.</p>}
                    <ul style={{ margin: 0, paddingLeft: 18 }}>
                      {(plan?.updates || []).map((u) => (
                        <li key={`${u.type}-${u.slug}`}>
                          <strong>{u.name || u.slug}</strong>{" "}
                          <span className="muted">{u.type}</span>
                          {u.to && <span className="badge warn"> → {u.to}</span>}
                        </li>
                      ))}
                    </ul>
                  </div>
                  <div>
                    <h4 style={{ fontSize: "0.95rem" }}>Ungenutzt / Aufräumen</h4>
                    {(audit?.unused_plugins || []).length === 0 && (audit?.unused_themes || []).length === 0 && (
                      <p className="muted">Nichts Auffälliges.</p>
                    )}
                    <ul style={{ margin: 0, paddingLeft: 18 }}>
                      {(audit?.unused_plugins || []).map((p) => (
                        <li key={p.slug}>Plugin {p.name} <span className="muted">({p.reason})</span></li>
                      ))}
                      {(audit?.unused_themes || []).map((t) => (
                        <li key={t.slug}>Theme {t.name} <span className="muted">({t.reason})</span></li>
                      ))}
                    </ul>
                    <h4 style={{ fontSize: "0.95rem", marginTop: 16 }}>Security</h4>
                    {(audit?.security_findings || []).length === 0 && <p className="muted">Keine offenen Findings.</p>}
                    <ul style={{ margin: 0, paddingLeft: 18 }}>
                      {(audit?.security_findings || []).slice(0, 8).map((f, i) => (
                        <li key={i}>
                          <span className={`badge ${f.severity}`}>{f.severity}</span> {f.title}
                        </li>
                      ))}
                    </ul>
                  </div>
                </div>
              </>
            )}
            {!run && <p className="muted">Noch kein Audit – „Jetzt auditieren“ starten.</p>}
          </div>
        );
      })()}
      {tab === "overview" && (
        <>
          {(detail.active_jobs || []).length > 0 && (
            <div className="surface surface-pad" style={{ marginBottom: 14 }}>
              <h3 style={{ marginTop: 0, fontSize: "1.05rem" }}>Laufende Prozesse</h3>
              <ProcessList
                onCancelled={() => load().catch(() => undefined)}
                items={(detail.active_jobs || []).map((j) => ({
                  id: j.id,
                  ...(j.progress_ui || { percent: 0, title: "Job", label: "…" }),
                }))}
              />
            </div>
          )}
          <div className="grid two">
            <div className="surface surface-pad">
              <h3 style={{ marginTop: 0, fontSize: "1.05rem" }}>Schnellaktionen</h3>
              <p className="muted" style={{ marginTop: 0 }}>Nur die häufigsten Schritte – Details in den Tabs.</p>
              <div className="row">
                <button className="btn" disabled={busy} type="button" onClick={() => run("backup_full", { label: "manual-full" })}>
                  Full Backup
                </button>
                <button className="btn secondary" disabled={busy} type="button" onClick={() => setTab("staging")}>
                  Development
                </button>
                <button className="btn secondary" disabled={busy} type="button" onClick={scan}>
                  Vuln-Scan
                </button>
                <button className="btn secondary" disabled={busy} type="button" onClick={() => run("ping")}>
                  Ping
                </button>
                <button
                  className="btn secondary"
                  disabled={busy}
                  type="button"
                  onClick={() => run("self_update")}
                >
                  {detail.agent_release?.update_available
                    ? `Agent → ${detail.agent_release.latest}`
                    : "Agent aktualisieren"}
                </button>
              </div>
            </div>
            <div className="surface surface-pad">
              <h3 style={{ marginTop: 0, fontSize: "1.05rem" }}>Status</h3>
              <div className="cell-sub" style={{ lineHeight: 1.8 }}>
                <div>Staging: {staging?.exists ? "aktiv" : "nicht angelegt"}</div>
                <div>Backups: {backups.length}</div>
                <div>Core: {site.inventory?.core?.version || "–"}
                  {site.inventory?.core?.update_available && (
                    <span className="badge warn"> → {site.inventory.core.update_available}</span>
                  )}
                </div>
              </div>
            </div>
          </div>
        </>
      )}

      {tab === "updates" && (() => {
        const isUpdateJob = (j: JobRow) =>
          /update|dry-run|multi/i.test(String(j.command || j.progress_ui?.title || ""));
        const activeUpdateJobs = (detail.active_jobs || []).filter(isUpdateJob);
        const recentUpdateJobs = (detail.jobs || [])
          .filter(isUpdateJob)
          .filter((j) => ["completed", "failed", "cancelled"].includes(String(j.status || "")))
          .slice(0, 8);
        // Prefer active, then recent finished (dedupe by id)
        const seen = new Set<string>();
        const updateJobs: JobRow[] = [];
        for (const j of [...activeUpdateJobs, ...recentUpdateJobs]) {
          if (seen.has(j.id)) continue;
          seen.add(j.id);
          updateJobs.push(j);
        }

        function outcomeFor(type: string, slug: string): { kind: "ok" | "error" | null; text?: string; dry?: boolean } {
          for (const j of updateJobs) {
            const cmd = String(j.command || "");
            const dry =
              cmd.startsWith("staging_update") ||
              (cmd === "update_batch" && j.payload?.mode === "staging");
            const items = j.progress_ui?.items || j.result?.results || [];
            if (items.length) {
              const hit = items.find((it) => (it.type || "plugin") === type && (it.slug || "") === slug);
              if (hit) {
                return hit.ok
                  ? { kind: "ok", text: dry ? "Dry-Run OK" : "OK", dry }
                  : { kind: "error", text: hit.error || j.error || "Fehler", dry };
              }
            }
            const jobSlug = j.payload?.slug || "";
            const matchesSingle =
              ((cmd === "staging_update_plugin" || cmd === "update_plugin") && type === "plugin" && jobSlug === slug) ||
              ((cmd === "staging_update_theme" || cmd === "update_theme") && type === "theme" && jobSlug === slug) ||
              (cmd === "update_core" && type === "core");
            if (!matchesSingle) continue;
            if (j.status === "completed" || j.progress_ui?.outcome === "ok") {
              return { kind: "ok", text: dry ? "Dry-Run OK" : "OK", dry };
            }
            if (j.status === "failed" || j.progress_ui?.outcome === "error") {
              return { kind: "error", text: j.error || j.progress_ui?.error || j.progress_ui?.label || "Fehler", dry };
            }
          }
          return { kind: null };
        }

        const selectable = [
          ...(site.inventory?.core?.update_available
            ? [{ type: "core", slug: "wordpress", name: "WordPress Core", to: site.inventory.core.update_available }]
            : []),
          ...plugins
            .filter((p) => p.update_available)
            .map((p) => ({ type: "plugin", slug: p.slug, name: p.name, to: p.update_available! })),
          ...themes
            .filter((t) => t.update_available)
            .map((t) => ({ type: "theme", slug: t.slug, name: t.name, to: t.update_available! })),
        ];
        const allKeys = selectable.map((u) => updateKey(u.type, u.slug));
        const allSelected = allKeys.length > 0 && allKeys.every((k) => selectedUpdates.has(k));

        return (
          <div className="surface surface-pad">
            {updateJobs.length > 0 && (
              <div style={{ marginBottom: 16 }}>
                <h3 style={{ marginTop: 0, fontSize: "1.05rem" }}>Updates / Dry-Runs</h3>
                <ProcessList
                  onCancelled={() => load().catch(() => undefined)}
                  items={updateJobs.map((j) => ({
                    id: j.id,
                    ...(j.progress_ui || {
                      percent: j.status === "completed" ? 100 : 0,
                      title: j.command || "Update",
                      label: j.status === "failed" ? (j.error || "Fehler") : j.status === "completed" ? "OK" : "…",
                      status: j.status,
                      outcome: j.status === "failed" ? "error" : j.status === "completed" ? "ok" : undefined,
                      error: j.error,
                      items: j.result?.results,
                    }),
                  }))}
                />
              </div>
            )}

            <div className="row" style={{ marginBottom: 14, justifyContent: "space-between", flexWrap: "wrap", gap: 8 }}>
              <p className="muted" style={{ margin: 0 }}>
                Mehrere auswählen für Batch. Dry-Run braucht Staging; Fortschritt erscheint oben.
              </p>
              <div className="row" style={{ gap: 8 }}>
                <button
                  className="btn secondary sm"
                  disabled={busy || selectedUpdates.size === 0 || !staging?.exists}
                  type="button"
                  onClick={() => runSelected("staging")}
                >
                  Dry-Run Auswahl ({selectedUpdates.size})
                </button>
                <button
                  className="btn sm"
                  disabled={busy || selectedUpdates.size === 0}
                  type="button"
                  onClick={() => {
                    if (confirm(`${selectedUpdates.size} Update(s) live ausführen?`)) {
                      runSelected("live");
                    }
                  }}
                >
                  Live Auswahl ({selectedUpdates.size})
                </button>
                <button
                  className="btn secondary sm"
                  disabled={busy || !site.inventory?.core?.update_available}
                  type="button"
                  onClick={() => run("update_core")}
                >
                  Core live
                </button>
              </div>
            </div>

            {(detail.prioritized_updates || []).filter((u) => u.update_to).length > 0 && (
              <>
                <h3 style={{ fontSize: "1rem", marginTop: 0 }}>Priorisierte Updates</h3>
                <table className="table" style={{ marginBottom: 20 }}>
                  <thead>
                    <tr>
                      <th style={{ width: 36 }} />
                      <th>Prio</th>
                      <th>Komponente</th>
                      <th>Version</th>
                      <th>Security</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    {(detail.prioritized_updates || [])
                      .filter((u) => u.update_to)
                      .map((u) => {
                        const key = updateKey(u.type, u.slug);
                        const outcome = outcomeFor(u.type, u.slug);
                        return (
                          <tr key={key}>
                            <td>
                              <input
                                type="checkbox"
                                checked={selectedUpdates.has(key)}
                                onChange={() => toggleUpdate(u.type, u.slug)}
                                aria-label={`${u.name} auswählen`}
                              />
                            </td>
                            <td className="muted">{u.priority_score}</td>
                            <td>
                              <div className="cell-title">{u.name}</div>
                              <div className="cell-sub">{u.type}</div>
                              {outcome.kind === "ok" && <span className="badge completed">{outcome.text}</span>}
                              {outcome.kind === "error" && (
                                <div className="cell-sub" style={{ color: "var(--danger)", marginTop: 4 }}>
                                  <span className="badge failed">Fehler</span> {outcome.text}
                                </div>
                              )}
                            </td>
                            <td>
                              {u.current || "–"}
                              <span className="badge warn"> → {u.update_to}</span>
                            </td>
                            <td>
                              {u.security ? (
                                <>
                                  <span className={`badge ${u.security.severity}`}>{u.security.severity}</span>
                                  {u.security.is_exploited && <span className="badge critical">exploited</span>}
                                  <div className="cell-sub">{u.security.title}</div>
                                </>
                              ) : (
                                <span className="muted">kein Advisory</span>
                              )}
                            </td>
                            <td>
                              <div className="action-menu">
                                {u.type !== "core" && (
                                  <button
                                    className="btn secondary sm"
                                    disabled={busy || !staging?.exists}
                                    type="button"
                                    onClick={() =>
                                      run(u.type === "theme" ? "staging_update_theme" : "staging_update_plugin", {
                                        slug: u.slug,
                                      })
                                    }
                                  >
                                    Dry-Run
                                  </button>
                                )}
                                <button
                                  className="btn secondary sm"
                                  disabled={busy}
                                  type="button"
                                  onClick={() =>
                                    run(
                                      u.type === "core" ? "update_core" : u.type === "theme" ? "update_theme" : "update_plugin",
                                      u.type === "core" ? {} : { slug: u.slug }
                                    )
                                  }
                                >
                                  Live
                                </button>
                              </div>
                            </td>
                          </tr>
                        );
                      })}
                  </tbody>
                </table>
              </>
            )}

            <div className="row" style={{ marginBottom: 8, justifyContent: "space-between" }}>
              <h3 style={{ fontSize: "1rem", margin: 0 }}>Alle Updates</h3>
              <button
                className="btn secondary sm"
                type="button"
                disabled={allKeys.length === 0}
                onClick={() => {
                  if (allSelected) setSelectedUpdates(new Set());
                  else setSelectedUpdates(new Set(allKeys));
                }}
              >
                {allSelected ? "Auswahl leeren" : "Alle auswählen"}
              </button>
            </div>
            <table className="table">
              <thead>
                <tr>
                  <th style={{ width: 36 }} />
                  <th>Komponente</th>
                  <th>Version</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {plugins.map((p) => {
                  const key = updateKey("plugin", p.slug);
                  const outcome = outcomeFor("plugin", p.slug);
                  return (
                    <tr key={p.slug + p.version}>
                      <td>
                        {p.update_available ? (
                          <input
                            type="checkbox"
                            checked={selectedUpdates.has(key)}
                            onChange={() => toggleUpdate("plugin", p.slug)}
                            aria-label={`${p.name} auswählen`}
                          />
                        ) : null}
                      </td>
                      <td>
                        <div className="cell-title">{p.name}</div>
                        <div className="cell-sub">Plugin{!p.active ? " · inaktiv" : ""}</div>
                        {outcome.kind === "ok" && <span className="badge completed">{outcome.text}</span>}
                        {outcome.kind === "error" && (
                          <div className="cell-sub" style={{ color: "var(--danger)", marginTop: 4 }}>
                            <span className="badge failed">Fehler</span> {outcome.text}
                          </div>
                        )}
                      </td>
                      <td>
                        {p.version}
                        {p.update_available && <span className="badge warn"> → {p.update_available}</span>}
                      </td>
                      <td>
                        {p.update_available && (
                          <div className="action-menu">
                            <button className="btn secondary sm" disabled={busy || !staging?.exists} type="button" onClick={() => run("staging_update_plugin", { slug: p.slug })}>
                              Dry-Run
                            </button>
                            <button className="btn secondary sm" disabled={busy} type="button" onClick={() => run("update_plugin", { slug: p.slug })}>
                              Live
                            </button>
                          </div>
                        )}
                      </td>
                    </tr>
                  );
                })}
                {themes.map((t) => {
                  const key = updateKey("theme", t.slug);
                  const outcome = outcomeFor("theme", t.slug);
                  return (
                    <tr key={t.slug}>
                      <td>
                        {t.update_available ? (
                          <input
                            type="checkbox"
                            checked={selectedUpdates.has(key)}
                            onChange={() => toggleUpdate("theme", t.slug)}
                            aria-label={`${t.name} auswählen`}
                          />
                        ) : null}
                      </td>
                      <td>
                        <div className="cell-title">{t.name}</div>
                        <div className="cell-sub">Theme</div>
                        {outcome.kind === "ok" && <span className="badge completed">{outcome.text}</span>}
                        {outcome.kind === "error" && (
                          <div className="cell-sub" style={{ color: "var(--danger)", marginTop: 4 }}>
                            <span className="badge failed">Fehler</span> {outcome.text}
                          </div>
                        )}
                      </td>
                      <td>
                        {t.version}
                        {t.update_available && <span className="badge warn"> → {t.update_available}</span>}
                      </td>
                      <td>
                        {t.update_available && (
                          <div className="action-menu">
                            <button className="btn secondary sm" disabled={busy || !staging?.exists} type="button" onClick={() => run("staging_update_theme", { slug: t.slug })}>
                              Dry-Run
                            </button>
                            <button className="btn secondary sm" disabled={busy} type="button" onClick={() => run("update_theme", { slug: t.slug })}>
                              Live
                            </button>
                          </div>
                        )}
                      </td>
                    </tr>
                  );
                })}
                {plugins.length === 0 && themes.length === 0 && (
                  <tr><td colSpan={4} className="muted">Kein Inventory – Site pairen oder Inventory laden.</td></tr>
                )}
              </tbody>
            </table>
          </div>
        );
      })()}

      {tab === "backups" && (
        <div className="surface surface-pad">
          <div className="surface surface-pad" style={{ marginBottom: 16, background: "rgba(0,0,0,0.18)" }}>
            <h4 style={{ marginTop: 0, fontSize: "0.95rem" }}>Automatischer Backup-Zeitplan</h4>
            <div className="row" style={{ alignItems: "center", gap: 14, flexWrap: "wrap" }}>
              <label className="row" style={{ gap: 6, alignItems: "center", fontSize: "0.85rem" }}>
                <input
                  type="checkbox"
                  disabled={busy}
                  checked={Boolean(site.backup_schedule?.enabled)}
                  onChange={(e) => saveBackupSchedule({ enabled: e.target.checked })}
                />
                Aktiv
              </label>
              <label className="row" style={{ gap: 6, alignItems: "center", fontSize: "0.85rem" }}>
                Uhrzeit
                <input
                  type="time"
                  disabled={busy}
                  defaultValue={site.backup_schedule?.time || "02:30"}
                  onBlur={(e) => {
                    if (e.target.value && e.target.value !== site.backup_schedule?.time) {
                      saveBackupSchedule({ time: e.target.value });
                    }
                  }}
                  style={{ width: 90 }}
                />
              </label>
              <label className="row" style={{ gap: 6, alignItems: "center", fontSize: "0.85rem" }}>
                Voll-Backup am
                <select
                  disabled={busy}
                  value={site.backup_schedule?.weekly_full_day ?? 0}
                  onChange={(e) => saveBackupSchedule({ weekly_full_day: Number(e.target.value) })}
                >
                  {["Sonntag", "Montag", "Dienstag", "Mittwoch", "Donnerstag", "Freitag", "Samstag"].map(
                    (d, i) => (
                      <option key={d} value={i}>{d}</option>
                    )
                  )}
                </select>
              </label>
              <label className="row" style={{ gap: 6, alignItems: "center", fontSize: "0.85rem" }}>
                <input
                  type="checkbox"
                  disabled={busy}
                  checked={site.backup_schedule?.incremental_daily ?? true}
                  onChange={(e) => saveBackupSchedule({ incremental_daily: e.target.checked })}
                />
                An den übrigen Tagen inkrementell
              </label>
            </div>
            <p className="muted" style={{ margin: "8px 0 0", fontSize: "0.8rem" }}>
              Backups laufen nachts leicht versetzt, werden auf den WWC-Server übertragen und
              belasten den Kundenserver nicht doppelt. Gespeicherte Ausschlüsse aus der
              Backup-Analyse gelten auch hier.
            </p>
          </div>

          <div className="row" style={{ marginBottom: 14 }}>
            <button className="btn secondary" disabled={busy} type="button" onClick={() => run("backup_scan", {})}>
              Analyse vor Backup
            </button>
            <button
              className="btn"
              disabled={busy}
              type="button"
              onClick={() =>
                run("backup_full", {
                  label: "manual-full",
                  ...(scanReport
                    ? { excludes: [...scanExcludes], save_settings: saveScanSettings }
                    : {}),
                })
              }
            >
              Full Backup
            </button>
            <button className="btn secondary" disabled={busy} type="button" onClick={() => run("backup_incremental", { label: "manual-incr" })}>
              Inkrementell
            </button>
            <button
              className="btn secondary"
              disabled={busy}
              type="button"
              onClick={async () => {
                setBusy(true);
                try {
                  await downloadSiteBackup(params.id, "latest");
                  setMsgTone("ok");
                  setMsg("Download gestartet");
                } catch (e) {
                  setMsgTone("error");
                  setMsg(e instanceof Error ? e.message : "Download fehlgeschlagen");
                } finally {
                  setBusy(false);
                }
              }}
            >
              Letzten Stand herunterladen
            </button>
          </div>

          {scanReport && (
            <div className="surface surface-pad" style={{ marginBottom: 16, background: "rgba(0,0,0,0.18)" }}>
              <h4 style={{ marginTop: 0, fontSize: "0.95rem" }}>
                Backup-Analyse
                <span className="muted" style={{ fontWeight: 400, marginLeft: 8, fontSize: "0.8rem" }}>
                  {scanReport.scanned_at ? new Date(scanReport.scanned_at).toLocaleString("de-DE") : ""}
                </span>
              </h4>
              <div className="meta-row" style={{ marginBottom: 10 }}>
                <span className="meta-chip">
                  Geschätzte Größe <strong>{formatBytes(scanReport.estimated_backup_bytes)}</strong>
                </span>
                <span className="meta-chip">
                  Dateien {scanReport.included?.files ?? 0} · DB {formatBytes(scanReport.db_bytes)}
                </span>
                <span className="meta-chip">
                  Ausgeschlossen {scanReport.excluded?.files ?? 0} ({formatBytes(scanReport.excluded?.bytes)})
                </span>
                <span className="meta-chip" title="Caches, Logs, Backups anderer Plugins – immer übersprungen">
                  Auto übersprungen {formatBytes(scanReport.auto_skipped?.bytes)}
                </span>
              </div>

              {(scanReport.top_files || []).length > 0 && (
                <>
                  <p className="muted" style={{ margin: "0 0 6px", fontSize: "0.85rem" }}>
                    Größte Dateien – Haken setzen = vom Backup ausschließen
                    (Dateien über {scanReport.settings?.max_file_mb || 0} MB sind vorausgewählt):
                  </p>
                  <table className="table" style={{ marginBottom: 10 }}>
                    <thead><tr><th></th><th>Datei</th><th>Größe</th></tr></thead>
                    <tbody>
                      {(scanReport.top_files || []).map((f) => (
                        <tr key={f.path}>
                          <td style={{ width: 30 }}>
                            <input
                              type="checkbox"
                              checked={scanExcludes.has(f.path)}
                              onChange={() =>
                                setScanExcludes((prev) => {
                                  const next = new Set(prev);
                                  if (next.has(f.path)) next.delete(f.path);
                                  else next.add(f.path);
                                  return next;
                                })
                              }
                            />
                          </td>
                          <td className="cell-sub" style={{ wordBreak: "break-all" }}>{f.path}</td>
                          <td className="muted" style={{ whiteSpace: "nowrap" }}>
                            {formatBytes(f.size_bytes)}
                            {f.status === "too_large" && <span className="badge warn" style={{ marginLeft: 6 }}>über Limit</span>}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </>
              )}

              {(scanReport.auto_skipped?.groups || []).length > 0 && (
                <p className="muted" style={{ margin: "0 0 10px", fontSize: "0.8rem" }}>
                  Automatisch übersprungen:{" "}
                  {(scanReport.auto_skipped?.groups || [])
                    .map((g) => `${g.path} (${formatBytes(g.size_bytes)})`)
                    .join(" · ")}
                </p>
              )}

              <div className="row" style={{ alignItems: "center", gap: 12, flexWrap: "wrap" }}>
                <label className="row" style={{ gap: 6, alignItems: "center", fontSize: "0.85rem" }}>
                  <input
                    type="checkbox"
                    checked={saveScanSettings}
                    onChange={(e) => setSaveScanSettings(e.target.checked)}
                  />
                  Ausschlüsse dauerhaft merken (gilt auch für automatische Backups)
                </label>
                <button
                  className="btn sm"
                  disabled={busy}
                  type="button"
                  onClick={() =>
                    run("backup_full", {
                      label: "manual-full",
                      excludes: [...scanExcludes],
                      save_settings: saveScanSettings,
                    })
                  }
                >
                  Full Backup mit dieser Auswahl ({scanExcludes.size} ausgeschlossen)
                </button>
              </div>
            </div>
          )}

          <table className="table">
            <thead><tr><th>Backup</th><th>Typ</th><th>Größe</th><th></th></tr></thead>
            <tbody>
              {backups.map((b) => (
                <tr key={b.id}>
                  <td>
                    <div className="cell-title">{b.id}</div>
                    <div className="cell-sub">{new Date(b.created_at).toLocaleString("de-DE")} · {b.label || "–"}</div>
                  </td>
                  <td>
                    <span className="badge">{b.type}</span>{" "}
                    {b.offsite ? (
                      <span className="badge completed" title="Archiv liegt sicher auf dem WWC-Server">WWC-Server</span>
                    ) : (
                      <span className="badge warn" title="Archiv liegt nur auf dem WordPress-Server">nur lokal</span>
                    )}
                  </td>
                  <td className="muted">{formatBytes(b.size_bytes)}</td>
                  <td>
                    <div className="action-menu">
                      <button
                        className="btn secondary sm"
                        disabled={busy}
                        type="button"
                        onClick={async () => {
                          setBusy(true);
                          try {
                            await downloadSiteBackup(params.id, b.id);
                          } catch (e) {
                            setMsgTone("error");
                            setMsg(e instanceof Error ? e.message : "Download fehlgeschlagen");
                          } finally {
                            setBusy(false);
                          }
                        }}
                      >
                        Download
                      </button>
                      <button
                        className="btn danger sm"
                        disabled={busy}
                        type="button"
                        onClick={() => {
                          if (confirm(`Site auf Backup ${b.id} zurücksetzen?`)) {
                            run("restore_backup", { backup_id: b.id });
                          }
                        }}
                      >
                        Restore
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
              {backups.length === 0 && (
                <tr><td colSpan={4} className="muted">Noch keine Backups.</td></tr>
              )}
            </tbody>
          </table>
        </div>
      )}

      {tab === "staging" && (
        <div className="surface surface-pad">
          <p className="muted" style={{ marginTop: 0 }}>
            Isolierte Dev-Umgebung zum Testen. Promote übernimmt geprüfte Änderungen auf Live.
          </p>
          {staging?.exists ? (
            <>
              <div className="meta-row" style={{ marginBottom: 16 }}>
                {stagingPortal?.portal_url && (
                  <span className="meta-chip">
                    Portal:{" "}
                    <a href={stagingPortal.portal_url} target="_blank" rel="noreferrer">{stagingPortal.portal_url}</a>
                  </span>
                )}
                {stagingPortal?.preview_url && (
                  <span className="meta-chip">
                    Staging:{" "}
                    <a href={stagingPortal.preview_url} target="_blank" rel="noreferrer">{stagingPortal.preview_url}</a>
                  </span>
                )}
                {stagingPortal?.access && (
                  <span className="meta-chip">
                    Login {stagingPortal.access.username}
                    {stagingPortal.access.password ? ` / ${stagingPortal.access.password}` : ""}
                  </span>
                )}
              </div>
              <div className="row">
                {stagingPortal?.admin_login_url && (
                  <a className="btn" href={stagingPortal.admin_login_url} target="_blank" rel="noreferrer">
                    WP-Admin öffnen
                  </a>
                )}
                {stagingPortal?.preview_url && (
                  <a className="btn secondary" href={stagingPortal.preview_url} target="_blank" rel="noreferrer">
                    Frontend prüfen
                  </a>
                )}
                {stagingPortal?.portal_url && (
                  <a className="btn secondary" href={stagingPortal.portal_url} target="_blank" rel="noreferrer">
                    Portal-Ansicht
                  </a>
                )}
                <button className="btn secondary" disabled={busy} type="button" onClick={grantStagingAdmin}>
                  Admin erneuern
                </button>
                <button
                  className="btn"
                  disabled={busy}
                  type="button"
                  onClick={() => {
                    if (confirm("Staging auf Live übernehmen? Zuvor wird ein Full-Backup erstellt.")) {
                      run("staging_promote", { backup_first: true });
                    }
                  }}
                >
                  Promote to Live
                </button>
                <button
                  className="btn danger"
                  disabled={busy}
                  type="button"
                  onClick={() => {
                    if (confirm("Staging löschen?")) run("staging_destroy");
                  }}
                >
                  Löschen
                </button>
              </div>
              <p className="muted" style={{ marginTop: 12 }}>
                Nach Dry-Run: <strong>WP-Admin öffnen</strong> (Magic-Login als Administrator) – dort Plugins, Themes und Seiten prüfen.
                „Admin erneuern“ erzeugt neuen Zugang, falls das Menü fehlt.
              </p>
            </>
          ) : (
            <button className="btn" disabled={busy} type="button" onClick={() => run("staging_create", { with_backup: true })}>
              Development-Umgebung erzeugen
            </button>
          )}

          <div className="surface surface-pad" style={{ marginTop: 18, background: "rgba(0,0,0,0.18)" }}>
            <h4 style={{ marginTop: 0, fontSize: "0.95rem" }}>
              WWC Dev-Kopie
              {devClone?.status === "ready" && <span className="badge completed" style={{ marginLeft: 8 }}>bereit</span>}
              {devClone?.status === "building" && <span className="badge running" style={{ marginLeft: 8 }}>wird gebaut…</span>}
              {devClone?.status === "failed" && <span className="badge failed" style={{ marginLeft: 8 }}>fehlgeschlagen</span>}
            </h4>
            <p className="muted" style={{ margin: "0 0 10px", fontSize: "0.85rem" }}>
              Kopie der Site aus dem letzten WWC-Server-Backup, gehostet auf dem WWC-Server im eigenen
              Docker-Stack (passende PHP-Version). Belastet den Kundenserver überhaupt nicht –
              ideal zum Testen von Updates und Änderungen.
            </p>
            {devClone?.status === "ready" && (
              <div className="meta-row" style={{ marginBottom: 10 }}>
                {devClone.url && (
                  <span className="meta-chip">
                    URL: <a href={devClone.url} target="_blank" rel="noreferrer">{devClone.url}</a>
                  </span>
                )}
                {devClone.admin_user && (
                  <span className="meta-chip">
                    Admin: {devClone.admin_user}{devClone.admin_pass ? ` / ${devClone.admin_pass}` : ""}
                  </span>
                )}
                {devClone.backup_id && <span className="meta-chip">Quelle: {devClone.backup_id}</span>}
                {devClone.php_image && <span className="meta-chip">PHP {devClone.php_image}</span>}
              </div>
            )}
            {devClone?.status === "failed" && devClone.error && (
              <div className="error" style={{ marginBottom: 10 }}>{devClone.error}</div>
            )}
            <div className="row">
              {devClone?.status === "ready" && devClone.url && (
                <>
                  <a className="btn" href={`${devClone.url}/wp-admin/`} target="_blank" rel="noreferrer">
                    WP-Admin öffnen
                  </a>
                  <a className="btn secondary" href={devClone.url} target="_blank" rel="noreferrer">
                    Frontend prüfen
                  </a>
                </>
              )}
              <button
                className="btn secondary"
                disabled={busy || devClone?.status === "building"}
                type="button"
                onClick={devCloneBuild}
              >
                {devClone?.status === "ready" ? "Neu aufbauen (aktuelles Backup)" : "Dev-Kopie erstellen"}
              </button>
              {devClone && devClone.status !== "building" && (
                <button
                  className="btn danger"
                  disabled={busy}
                  type="button"
                  onClick={() => {
                    if (confirm("WWC Dev-Kopie löschen?")) devCloneDestroy();
                  }}
                >
                  Löschen
                </button>
              )}
            </div>
          </div>
        </div>
      )}

      {tab === "activity" && (
        <div className="grid two">
          <div className="surface surface-pad">
            <h3 style={{ marginTop: 0, fontSize: "1.05rem" }}>Events</h3>
            {detail.events.map((ev) => (
              <div className="event-item" key={ev.id}>
                <div className="list-card-top">
                  <div>
                    <strong>{ev.title}</strong>
                    <div className="cell-sub">{new Date(ev.occurred_at).toLocaleString("de-DE")}</div>
                  </div>
                  <span className={`badge ${ev.severity}`}>{ev.severity}</span>
                </div>
              </div>
            ))}
            {detail.events.length === 0 && <p className="muted">Keine Events.</p>}
          </div>
          <div className="surface surface-pad">
            <h3 style={{ marginTop: 0, fontSize: "1.05rem" }}>Jobs & Fortschritt</h3>
            <div className="process-list">
              {detail.jobs.map((j) => (
                <div key={j.id}>
                  {j.progress_ui ? (
                    <ProcessBar
                      jobId={j.status === "pending" || j.status === "running" ? j.id : undefined}
                      onCancelled={() => load().catch(() => undefined)}
                      progress={j.progress_ui}
                    />
                  ) : (
                    <div className="event-item">
                      <strong>{j.command}</strong> <span className={`badge ${j.status}`}>{j.status}</span>
                    </div>
                  )}
                  {j.error && <div className="error" style={{ marginTop: 6 }}>{j.error}</div>}
                </div>
              ))}
            </div>
            {detail.jobs.length === 0 && <p className="muted">Keine Jobs.</p>}
          </div>
        </div>
      )}
    </Shell>
  );
}
