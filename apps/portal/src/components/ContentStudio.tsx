"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";

type Intel = {
  site?: { name?: string; tagline?: string; url?: string; wp_version?: string; php_version?: string };
  theme?: { name?: string; version?: string; parent?: string | null; stylesheet?: string };
  editors?: { default?: string; builders?: string[]; notes?: string };
  branding?: { logo_url?: string | null };
  homepage?: { show_on_front?: string };
  pages?: Array<{ id: number; title: string; editor: string; status: string; url: string }>;
  posts?: Array<{ id: number; title: string; status: string }>;
  plugins?: Array<{ name: string; slug: string; active: boolean; version?: string }>;
  theme_files?: Array<{ path: string; bytes: number }>;
  widgets?: Array<{ id: string; widgets: number }>;
  permalinks?: string;
  counts?: { pages?: number; posts?: number; plugins_active?: number; plugins_total?: number };
};

type DetailField = { name: string; before?: string | number | null; after?: string | number | null };

type Detail = {
  op?: string;
  label?: string;
  ok?: boolean | null;
  title?: string | number | null;
  url?: string | null;
  note?: string | null;
  undoable?: boolean;
  fields?: DetailField[];
};

type Result = {
  ok?: boolean;
  op?: string;
  error?: string;
  url?: string;
  id?: number;
  title?: string;
  key?: string;
  slug?: string;
  path?: string;
  note?: string;
  before?: Record<string, unknown>;
  after?: Record<string, unknown>;
};

type Draft = {
  prompt?: string;
  summary?: string;
  status?: string;
  target?: string;
  ops?: Array<Record<string, unknown>>;
  details?: Detail[];
  error?: string | null;
  dev_results?: Result[];
  live_results?: Result[];
  undo_ops?: Array<Record<string, unknown>>;
  undoable?: boolean;
  applied_dev_at?: string;
  promoted_at?: string;
};

type HistoryItem = {
  prompt?: string;
  summary?: string;
  status?: string;
  target?: string;
  error?: string | null;
  details?: Detail[];
  dev_results?: Result[];
  undoable?: boolean;
  at?: string;
};

type Studio = {
  intel?: Intel | null;
  intel_source?: string | null;
  scanned_at?: string | null;
  draft?: Draft | null;
  history?: HistoryItem[];
  target?: "live" | "clone";
  live_paired?: boolean;
  clone_ready?: boolean;
  clone_url?: string | null;
  scan_status?: string | null;
  pairing_note?: string;
};

function opLabel(op: Record<string, unknown> | Result): string {
  const rec = op as Record<string, unknown>;
  const name = String(rec.op || "Änderung");
  const map: Record<string, string> = {
    create_post: "Neue Seite/Beitrag",
    update_post: "Seite anpassen",
    set_option: "Einstellung",
    set_logo: "Logo",
    upload_media: "Datei",
    plugin_activate: "Plugin an",
    plugin_deactivate: "Plugin aus",
    plugin_update: "Plugin-Update",
    theme_update: "Theme-Update",
    set_custom_css: "Custom-CSS",
    update_theme_file: "Theme-Datei",
  };
  const title = rec.title
    ? String(rec.title)
    : rec.id
      ? `#${rec.id}`
      : rec.key
        ? String(rec.key)
        : rec.slug
          ? String(rec.slug)
          : rec.path
            ? String(rec.path)
            : "";
  return `${map[name] || name}${title ? `: ${title}` : ""}`;
}

function ChangeList({
  details,
  ops,
  onPreview,
}: {
  details: Detail[];
  ops: Array<Record<string, unknown>>;
  onPreview: (url: string) => void;
}) {
  if (details.length === 0) {
    return (
      <>
        {ops.map((op, i) => (
          <div key={i} className="cell-sub" style={{ fontSize: "0.82rem" }}>
            {opLabel(op)}
          </div>
        ))}
      </>
    );
  }
  return (
    <div style={{ marginTop: 10 }}>
      {details.map((d, i) => (
        <div key={i} className="change-card">
          <div className="row" style={{ justifyContent: "space-between" }}>
            <strong>
              {d.ok === true && <span className="badge completed">OK</span>}
              {d.ok === false && <span className="badge failed">Fehler</span>}
              {d.ok == null && <span className="badge pending">geplant</span>}{" "}
              {d.label || d.op}
              {d.title ? `: ${d.title}` : ""}
            </strong>
            {d.url && (
              <span className="row" style={{ gap: 8 }}>
                <button className="btn secondary sm" type="button" onClick={() => onPreview(d.url || "")}>
                  Vorschau
                </button>
                <a className="btn secondary sm" href={d.url} target="_blank" rel="noreferrer">
                  Öffnen
                </a>
              </span>
            )}
          </div>
          {(d.fields || []).map((f) => (
            <div key={f.name} className="change-field">
              <span className="muted">{f.name}</span>
              {f.before != null && f.after != null && String(f.before) !== String(f.after) ? (
                <pre>
                  Vorher: {String(f.before) || "—"}
                  {"\n"}Nachher: {String(f.after) || "—"}
                </pre>
              ) : (
                <pre>{String(f.after ?? f.before ?? "—")}</pre>
              )}
            </div>
          ))}
          {d.note && <p className="muted" style={{ margin: "6px 0 0", fontSize: "0.8rem" }}>{d.note}</p>}
        </div>
      ))}
    </div>
  );
}

export function ContentStudio({
  siteId,
  initial,
  onRefresh,
}: {
  siteId: string;
  initial: Studio | null | undefined;
  onRefresh: () => Promise<void>;
}) {
  const [studio, setStudio] = useState<Studio>(initial || {});
  const [prompt, setPrompt] = useState("");
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);
  const [preview, setPreview] = useState<string | null>(null);
  const target = studio.target === "live" ? "live" : "clone";
  const intel = studio.intel;
  const draft = studio.draft;
  const results = draft?.dev_results || draft?.live_results || [];
  const previewUrl = preview || results.find((r) => r.ok && r.url)?.url || null;
  const intelMatches = studio.intel_source === target;
  const scanPending = studio.scan_status === "pending";
  const jobPending = draft?.status === "promoting" || draft?.status === "undoing";
  const targetReady = target === "clone" ? !!studio.clone_ready : !!studio.live_paired;
  const details = draft?.details || [];

  useEffect(() => {
    if (initial) {
      setStudio(initial);
    }
  }, [initial]);

  useEffect(() => {
    if (!scanPending && !jobPending) {
      return;
    }
    const t = window.setInterval(async () => {
      try {
        const res = await api<{ data: Studio }>(`/sites/${siteId}/content-studio`);
        setStudio(res.data);
        if (res.data.scan_status === "ready" && scanPending) {
          setMsg("Scan fertig. Die KI kennt Theme, Plugins und Inhalte.");
          await onRefresh();
        }
        if (res.data.scan_status === "failed") {
          setMsg("Live-Scan fehlgeschlagen. Agent-Version prüfen.");
        }
        if (res.data.draft?.status === "promoted") {
          setMsg("Änderung ist live.");
          await onRefresh();
        }
        if (res.data.draft?.status === "undone") {
          setMsg("Änderung wurde rückgängig gemacht.");
          await onRefresh();
        }
      } catch {
        /* nächster Tick */
      }
    }, 2500);
    return () => window.clearInterval(t);
  }, [scanPending, jobPending, siteId, onRefresh]);

  async function run(path: string, body?: unknown) {
    setBusy(true);
    setMsg(null);
    try {
      const res = await api<{ data: Studio }>(`/sites/${siteId}/content-studio/${path}`, {
        method: "POST",
        body: body !== undefined ? JSON.stringify(body) : undefined,
      });
      setStudio(res.data);
      const firstUrl = res.data.draft?.dev_results?.find((r) => r.ok && r.url)?.url;
      if (firstUrl) {
        setPreview(firstUrl);
        setMsg("Änderung ist in der isolierten Kopie. Prüfe die Vorschau unten.");
      } else if (res.data.draft?.status === "applied_dev") {
        setMsg("Änderung ist in der isolierten Kopie umgesetzt.");
      } else if (res.data.draft?.status === "promoting") {
        setMsg("Auftrag an den Live-Agenten übergeben.");
      } else if (res.data.scan_status === "pending") {
        setMsg("Live-Scan läuft über den gepaarten Agenten…");
      } else if (res.data.draft?.status === "undone") {
        setMsg("Änderung wurde rückgängig gemacht.");
      } else if (res.data.draft?.status === "undoing") {
        setMsg("Rücknahme läuft über den Live-Agenten…");
      } else if (res.data.draft?.error) {
        setMsg(res.data.draft.error);
      } else if (path === "scan" && res.data.intel) {
        setMsg("Scan fertig.");
      }
      if (path === "run") {
        setPrompt("");
      }
      await onRefresh();
    } catch (e) {
      setMsg(e instanceof Error ? e.message : "Fehler");
    } finally {
      setBusy(false);
    }
  }

  async function switchTarget(next: "live" | "clone") {
    if (next === target) {
      return;
    }
    await run("target", { target: next });
  }

  async function uploadLogo(file: File) {
    setBusy(true);
    setMsg(null);
    try {
      const fd = new FormData();
      fd.append("file", file);
      const stored = await api<{ data: { clone_path?: string | null; filename: string } }>(
        `/sites/${siteId}/content-studio/upload`,
        { method: "POST", body: fd }
      );
      const fresh = await api<{ data: Studio }>(`/sites/${siteId}/content-studio`);
      setStudio(fresh.data);
      if (!stored.data.clone_path) {
        setMsg("Datei liegt auf dem Server. Isolierte Kopie erstellen, dann erneut hochladen.");
        return;
      }
      setMsg("Logo liegt in der isolierten Kopie. Jetzt den Auftrag umsetzen.");
      await onRefresh();
    } catch (e) {
      setMsg(e instanceof Error ? e.message : "Upload fehlgeschlagen");
    } finally {
      setBusy(false);
    }
  }

  const plugins = intel?.plugins || [];
  const activePlugins = plugins.filter((p) => p.active);
  const inactivePlugins = plugins.filter((p) => !p.active);

  return (
    <div className="surface surface-pad">
      <h3 style={{ marginTop: 0, fontSize: "1.05rem" }}>KI-Editor</h3>
      <p className="muted" style={{ marginTop: 0 }}>
        Zuerst die Site vollständig scannen. Dann Ziel wählen: isolierte Kopie oder Live.
        Beide hängen an derselben Site – der Pairing-Key bleibt nur auf Live, die Kopie wird über den WWC-Server gesteuert.
      </p>

      <div className="row" style={{ marginBottom: 14, alignItems: "center" }}>
        <div className="segmented">
          <button
            type="button"
            className={target === "clone" ? "active" : ""}
            disabled={busy || !studio.clone_ready}
            onClick={() => switchTarget("clone")}
          >
            Isolierte Kopie
          </button>
          <button
            type="button"
            className={target === "live" ? "active" : ""}
            disabled={busy || !studio.live_paired}
            onClick={() => switchTarget("live")}
          >
            Live
          </button>
        </div>
        {target === "live" && <span className="badge running">Live-Agent</span>}
        {target === "clone" && <span className="badge completed">WWC-Kopie</span>}
      </div>

      {!studio.clone_ready && (
        <p className="muted">Isolierte Kopie: zuerst im Tab Development auf dem WWC-Server erstellen.</p>
      )}
      {!studio.live_paired && (
        <p className="muted">Live: Site ist nicht verbunden. Pairing im Tab Verbindung.</p>
      )}
      {busy && (
        <p className="muted">
          {target === "live" ? "KI spricht mit dem Live-Agenten…" : "KI arbeitet in der isolierten Kopie…"}
        </p>
      )}
      {scanPending && <p className="muted">Vollständiger Live-Scan läuft. Theme, Plugins und Inhalte werden erfasst.</p>}
      {msg && (
        <p className={msg.includes("fehl") || msg.includes("Fehler") || msg.includes("nicht") ? "error" : "muted"}>
          {msg}
        </p>
      )}

      <div className="row" style={{ marginBottom: 14 }}>
        <button
          className="btn"
          type="button"
          disabled={busy || !targetReady || scanPending}
          onClick={() => run("scan", { target })}
        >
          Site vollständig scannen
        </button>
        {studio.clone_url && target === "clone" && (
          <a className="btn secondary" href={studio.clone_url} target="_blank" rel="noreferrer">
            Kopie öffnen
          </a>
        )}
        <label className="btn secondary" style={{ cursor: busy ? "default" : "pointer" }}>
          Logo hochladen
          <input
            type="file"
            accept="image/*"
            hidden
            disabled={busy}
            onChange={(e) => {
              const file = e.target.files?.[0];
              if (file) uploadLogo(file);
              e.target.value = "";
            }}
          />
        </label>
      </div>

      {intel && intelMatches && (
        <div className="surface surface-pad" style={{ background: "rgba(0,0,0,0.18)", marginBottom: 16 }}>
          <p style={{ margin: "0 0 8px" }}>
            <strong>{intel.site?.name}</strong>{" "}
            <span className="muted">
              WP {intel.site?.wp_version || "?"}
              {intel.theme?.name ? ` · ${intel.theme.name}` : ""}
              {intel.theme?.version ? ` ${intel.theme.version}` : ""}
              {intel.editors?.builders?.length ? ` · ${intel.editors.builders.join(", ")}` : ` · Editor ${intel.editors?.default || "Gutenberg"}`}
              {intel.counts
                ? ` · ${intel.counts.pages} Seiten, ${intel.counts.posts} Beiträge, ${intel.counts.plugins_active}/${intel.counts.plugins_total ?? plugins.length} Plugins`
                : ""}
            </span>
          </p>
          {intel.editors?.notes && <p className="muted" style={{ margin: "0 0 8px", fontSize: "0.85rem" }}>{intel.editors.notes}</p>}
          {intel.branding?.logo_url && (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={intel.branding.logo_url} alt="Logo" style={{ maxHeight: 48, marginBottom: 8 }} />
          )}
          <div style={{ display: "flex", flexWrap: "wrap", gap: 6, marginBottom: 10 }}>
            {(intel.pages || []).slice(0, 16).map((p) => (
              <span key={p.id} className="meta-chip">
                {p.title} <span className="muted">({p.editor})</span>
              </span>
            ))}
          </div>
          {activePlugins.length > 0 && (
            <p className="muted" style={{ margin: "0 0 6px", fontSize: "0.82rem" }}>
              Aktiv: {activePlugins.slice(0, 18).map((p) => p.name).join(", ")}
              {activePlugins.length > 18 ? "…" : ""}
            </p>
          )}
          {inactivePlugins.length > 0 && (
            <p className="muted" style={{ margin: 0, fontSize: "0.82rem" }}>
              Inaktiv: {inactivePlugins.slice(0, 10).map((p) => p.name).join(", ")}
              {inactivePlugins.length > 10 ? "…" : ""}
            </p>
          )}
        </div>
      )}
      {intel && !intelMatches && (
        <p className="muted">Der letzte Scan gehört zum anderen Ziel. Bitte dieses Ziel vollständig scannen.</p>
      )}

      <div className="field">
        <label>Auftrag an die KI</label>
        <textarea
          rows={4}
          value={prompt}
          placeholder="z. B. Plugin Autoptimize deaktivieren, Custom-CSS für die Navigation, Landingpage für Flottenleasing…"
          onChange={(e) => setPrompt(e.target.value)}
          disabled={busy}
        />
      </div>
      <div className="row">
        <button
          className="btn"
          type="button"
          disabled={busy || !prompt.trim() || !targetReady || scanPending}
          onClick={() => {
            if (target === "live" && !confirm("Diesen Auftrag direkt auf der Live-Site ausführen?")) {
              return;
            }
            run("run", { prompt, target, confirm_live: target === "live" });
          }}
        >
          {target === "live" ? "Auf Live umsetzen" : "In der Kopie umsetzen"}
        </button>
        <button
          className="btn secondary"
          type="button"
          disabled={busy || !prompt.trim() || scanPending}
          onClick={() => run("plan", { prompt, target })}
        >
          Nur Plan
        </button>
      </div>

      {draft?.summary && (
        <div style={{ marginTop: 16 }}>
          <p>
            <strong>Ergebnis</strong>{" "}
            {draft.status === "planned" && <span className="badge pending">geplant</span>}
            {draft.status === "applied_dev" && <span className="badge completed">in Kopie</span>}
            {draft.status === "promoted" && <span className="badge completed">live</span>}
            {draft.status === "failed" && <span className="badge failed">fehlgeschlagen</span>}
            {draft.status === "undone" && <span className="badge pending">rückgängig</span>}
            {draft.status === "undoing" && <span className="badge running">wird rückgängig…</span>}
          </p>
          {draft.prompt && <p className="muted" style={{ marginTop: 0 }}>Auftrag: {draft.prompt}</p>}
          <p className="muted">{draft.summary}</p>
          <ChangeList details={details} ops={draft.ops || []} onPreview={(url) => setPreview(url)} />
          {draft.error && <div className="error">{draft.error}</div>}

          {previewUrl && target === "clone" && (
            <div style={{ marginTop: 14 }}>
              <div className="row" style={{ justifyContent: "space-between", marginBottom: 8 }}>
                <strong>Vorschau in der isolierten Kopie</strong>
                <a href={previewUrl} target="_blank" rel="noreferrer">
                  Vollbild
                </a>
              </div>
              <iframe
                title="Kopie-Vorschau"
                src={previewUrl}
                style={{ width: "100%", height: 420, border: "1px solid rgba(255,255,255,0.12)", borderRadius: 8, background: "#fff" }}
              />
            </div>
          )}

          <div className="row" style={{ marginTop: 12 }}>
            {draft.status === "planned" && target === "clone" && (
              <button
                className="btn"
                type="button"
                disabled={busy || !studio.clone_ready}
                onClick={() => run("apply-dev")}
              >
                Plan in der Kopie anwenden
              </button>
            )}
            {draft.status === "planned" && target === "live" && (
              <button
                className="btn"
                type="button"
                disabled={busy || !studio.live_paired}
                onClick={() => {
                  if (confirm("Geplanten Auftrag jetzt auf der Live-Site ausführen?")) {
                    run("apply-live", { confirm_live: true });
                  }
                }}
              >
                Plan live anwenden
              </button>
            )}
            {draft.status === "applied_dev" && (
              <button
                className="btn secondary"
                type="button"
                disabled={busy}
                onClick={() => {
                  if (confirm("Geprüfte Änderungen jetzt auf die Live-Site übernehmen?")) run("promote");
                }}
              >
                Nach Prüfung live übernehmen
              </button>
            )}
            {draft.undoable && (draft.status === "applied_dev" || draft.status === "promoted") && (
              <button
                className="btn danger"
                type="button"
                disabled={busy}
                onClick={() => {
                  const live = draft.target === "live" || draft.status === "promoted";
                  if (!confirm(live ? "Diese Live-Änderung wirklich rückgängig machen?" : "Diese Änderung in der isolierten Kopie rückgängig machen?")) {
                    return;
                  }
                  run("undo", { confirm_live: live, at: draft.applied_dev_at || draft.promoted_at || undefined });
                }}
              >
                Rückgängig
              </button>
            )}
          </div>
        </div>
      )}

      {(studio.history || []).length > 0 && (
        <div style={{ marginTop: 20 }}>
          <h4 style={{ fontSize: "0.95rem" }}>Letzte Aufträge</h4>
          {(studio.history || []).slice(1).map((item, i) => (
            <div key={`${item.at || i}`} className="change-card">
              <div>
                <span className={`badge ${item.status === "applied_dev" || item.status === "promoted" ? "completed" : item.status === "failed" ? "failed" : "pending"}`}>
                  {item.status === "applied_dev" ? "in Kopie" : item.status === "promoted" ? "live" : item.status === "undone" ? "rückgängig" : item.status || ""}
                </span>{" "}
                {item.target === "live" ? "Live" : "Kopie"} · {item.prompt || item.summary}
              </div>
              <ChangeList details={item.details || []} ops={[]} onPreview={(url) => setPreview(url)} />
              {item.undoable && (item.status === "applied_dev" || item.status === "promoted") && (
                <button
                  className="btn secondary sm"
                  type="button"
                  disabled={busy}
                  onClick={() => {
                    const live = item.target === "live" || item.status === "promoted";
                    if (!confirm("Diesen Auftrag rückgängig machen?")) return;
                    run("undo", { confirm_live: live, at: item.at });
                  }}
                >
                  Rückgängig
                </button>
              )}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
