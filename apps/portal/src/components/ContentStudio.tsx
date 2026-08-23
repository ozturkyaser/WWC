"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";

type Intel = {
  site?: { name?: string; tagline?: string; url?: string; wp_version?: string };
  theme?: { name?: string; version?: string; parent?: string | null };
  editors?: { default?: string; builders?: string[]; notes?: string };
  branding?: { logo_url?: string | null };
  homepage?: { show_on_front?: string };
  pages?: Array<{ id: number; title: string; editor: string; status: string; url: string }>;
  posts?: Array<{ id: number; title: string; status: string }>;
  plugins?: Array<{ name: string; slug: string; active: boolean; version?: string }>;
  counts?: { pages?: number; posts?: number; plugins_active?: number };
};

type Result = {
  ok?: boolean;
  op?: string;
  error?: string;
  url?: string;
  id?: number;
  title?: string;
};

type Draft = {
  prompt?: string;
  summary?: string;
  status?: string;
  ops?: Array<Record<string, unknown>>;
  error?: string | null;
  dev_results?: Result[];
};

type HistoryItem = {
  prompt?: string;
  summary?: string;
  status?: string;
  error?: string | null;
  dev_results?: Result[];
  at?: string;
};

type Studio = {
  intel?: Intel | null;
  intel_source?: string | null;
  scanned_at?: string | null;
  draft?: Draft | null;
  history?: HistoryItem[];
  clone_ready?: boolean;
  clone_url?: string | null;
};

function opLabel(op: Record<string, unknown> | Result): string {
  const name = String(op.op || "Änderung");
  const map: Record<string, string> = {
    create_post: "Neue Seite/Beitrag",
    update_post: "Seite anpassen",
    set_option: "Einstellung",
    set_logo: "Logo",
    upload_media: "Datei",
  };
  const title = op.title ? String(op.title) : op.id ? `#${op.id}` : op.key ? String(op.key) : "";
  return `${map[name] || name}${title ? `: ${title}` : ""}`;
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
  const intel = studio.intel;
  const draft = studio.draft;
  const results = draft?.dev_results || [];
  const previewUrl = preview || results.find((r) => r.ok && r.url)?.url || null;

  useEffect(() => {
    if (initial) {
      setStudio(initial);
    }
  }, [initial]);

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
        setMsg("Änderung ist in der isolierten Umgebung. Prüfe die Vorschau unten.");
      } else if (res.data.draft?.status === "applied_dev") {
        setMsg("Änderung ist in der isolierten Umgebung umgesetzt.");
      } else if (res.data.draft?.error) {
        setMsg(res.data.draft.error);
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
        setMsg("Datei liegt auf dem Server. Dev-Kopie erstellen, dann erneut hochladen.");
        return;
      }
      setMsg("Logo liegt in der Dev-Kopie. Jetzt den Auftrag „Logo ersetzen“ umsetzen oder „In Dev anwenden“.");
      await onRefresh();
    } catch (e) {
      setMsg(e instanceof Error ? e.message : "Upload fehlgeschlagen");
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="surface surface-pad">
      <h3 style={{ marginTop: 0, fontSize: "1.05rem" }}>KI-Editor</h3>
      <p className="muted" style={{ marginTop: 0 }}>
        Auftrag beschreiben – die KI setzt ihn sofort in der isolierten Umgebung um.
        Das Ergebnis erscheint hier mit Vorschau. Live erst nach Freigabe.
      </p>
      {!studio.clone_ready && (
        <p className="error">Zuerst im Tab Development die isolierte Umgebung auf dem WWC-Server erstellen.</p>
      )}
      {busy && <p className="muted">KI arbeitet in der Dev-Umgebung… Scan, Plan und Umsetzung können eine Minute dauern.</p>}
      {msg && (
        <p className={msg.includes("fehl") || msg.includes("Fehler") || msg.includes("nicht") ? "error" : "muted"}>
          {msg}
        </p>
      )}

      <div className="row" style={{ marginBottom: 14 }}>
        <button className="btn secondary" type="button" disabled={busy || !studio.clone_ready} onClick={() => run("scan")}>
          Dev-Umgebung scannen
        </button>
        {studio.clone_url && (
          <a className="btn secondary" href={studio.clone_url} target="_blank" rel="noreferrer">
            Dev öffnen
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

      {intel && (
        <div className="surface surface-pad" style={{ background: "rgba(0,0,0,0.18)", marginBottom: 16 }}>
          <p style={{ margin: "0 0 8px" }}>
            <strong>{intel.site?.name}</strong>{" "}
            <span className="muted">
              {intel.theme?.name}
              {intel.theme?.version ? ` ${intel.theme.version}` : ""}
              {intel.editors?.builders?.length ? ` · ${intel.editors.builders.join(", ")}` : ` · Editor ${intel.editors?.default || "Gutenberg"}`}
              {intel.counts ? ` · ${intel.counts.pages} Seiten, ${intel.counts.posts} Beiträge, ${intel.counts.plugins_active} Plugins` : ""}
            </span>
          </p>
          {intel.editors?.notes && <p className="muted" style={{ margin: "0 0 8px", fontSize: "0.85rem" }}>{intel.editors.notes}</p>}
          {intel.branding?.logo_url && (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={intel.branding.logo_url} alt="Logo" style={{ maxHeight: 48, marginBottom: 8 }} />
          )}
          <div style={{ display: "flex", flexWrap: "wrap", gap: 6 }}>
            {(intel.pages || []).slice(0, 12).map((p) => (
              <span key={p.id} className="meta-chip">
                {p.title} <span className="muted">({p.editor})</span>
              </span>
            ))}
          </div>
        </div>
      )}

      <div className="field">
        <label>Auftrag an die KI</label>
        <textarea
          rows={4}
          value={prompt}
          placeholder="z. B. Neue Landingpage für Flottenleasing, oder Blogbeitrag über Winterreifen, oder Logo ersetzen…"
          onChange={(e) => setPrompt(e.target.value)}
          disabled={busy}
        />
      </div>
      <div className="row">
        <button
          className="btn"
          type="button"
          disabled={busy || !prompt.trim() || !studio.clone_ready}
          onClick={() => run("run", { prompt })}
        >
          In Dev umsetzen
        </button>
        <button
          className="btn secondary"
          type="button"
          disabled={busy || !prompt.trim()}
          onClick={() => run("plan", { prompt })}
        >
          Nur Plan
        </button>
      </div>

      {draft?.summary && (
        <div style={{ marginTop: 16 }}>
          <p>
            <strong>Ergebnis</strong>{" "}
            {draft.status === "planned" && <span className="badge pending">geplant</span>}
            {draft.status === "applied_dev" && <span className="badge completed">in Dev</span>}
            {draft.status === "promoted" && <span className="badge completed">live</span>}
            {draft.status === "failed" && <span className="badge failed">fehlgeschlagen</span>}
            {draft.status === "promoting" && <span className="badge running">wird live übernommen…</span>}
          </p>
          {draft.prompt && <p className="muted" style={{ marginTop: 0 }}>Auftrag: {draft.prompt}</p>}
          <p className="muted">{draft.summary}</p>
          {(draft.ops || []).map((op, i) => (
            <div key={i} className="cell-sub" style={{ fontSize: "0.82rem" }}>
              {opLabel(op)}
            </div>
          ))}
          {draft.error && <div className="error">{draft.error}</div>}

          {results.length > 0 && (
            <div style={{ marginTop: 12 }}>
              <h4 style={{ fontSize: "0.95rem", margin: "0 0 8px" }}>Was geändert wurde</h4>
              {results.map((row, i) => (
                <div key={i} className="row" style={{ justifyContent: "space-between", marginBottom: 6, flexWrap: "wrap" }}>
                  <span>
                    <span className={`badge ${row.ok ? "completed" : "failed"}`}>{row.ok ? "OK" : "Fehler"}</span>{" "}
                    {opLabel(row)}
                    {row.error ? <span className="error"> — {row.error}</span> : null}
                  </span>
                  {row.url && (
                    <span className="row" style={{ gap: 8 }}>
                      <button className="btn secondary" type="button" onClick={() => setPreview(row.url || null)}>
                        Vorschau
                      </button>
                      <a className="btn secondary" href={row.url} target="_blank" rel="noreferrer">
                        In Dev öffnen
                      </a>
                    </span>
                  )}
                </div>
              ))}
            </div>
          )}

          {previewUrl && (
            <div style={{ marginTop: 14 }}>
              <div className="row" style={{ justifyContent: "space-between", marginBottom: 8 }}>
                <strong>Vorschau in der Dev-Umgebung</strong>
                <a href={previewUrl} target="_blank" rel="noreferrer">
                  Vollbild
                </a>
              </div>
              <iframe
                title="Dev-Vorschau"
                src={previewUrl}
                style={{ width: "100%", height: 420, border: "1px solid rgba(255,255,255,0.12)", borderRadius: 8, background: "#fff" }}
              />
            </div>
          )}

          <div className="row" style={{ marginTop: 12 }}>
            {draft.status === "planned" && (
              <button
                className="btn"
                type="button"
                disabled={busy || !studio.clone_ready}
                onClick={() => run("apply-dev")}
              >
                Plan in Dev anwenden
              </button>
            )}
            <button
              className="btn secondary"
              type="button"
              disabled={busy || draft.status !== "applied_dev"}
              onClick={() => {
                if (confirm("Geprüfte Änderungen jetzt auf die Live-Site übernehmen?")) run("promote");
              }}
            >
              Nach Prüfung live übernehmen
            </button>
          </div>
        </div>
      )}

      {(studio.history || []).length > 1 && (
        <div style={{ marginTop: 20 }}>
          <h4 style={{ fontSize: "0.95rem" }}>Letzte Aufträge</h4>
          {(studio.history || []).slice(1).map((item, i) => (
            <div key={`${item.at || i}`} className="cell-sub" style={{ fontSize: "0.82rem", marginBottom: 6 }}>
              <span className={`badge ${item.status === "applied_dev" ? "completed" : item.status === "failed" ? "failed" : "pending"}`}>
                {item.status === "applied_dev" ? "in Dev" : item.status || ""}
              </span>{" "}
              {item.prompt || item.summary}
              {(item.dev_results || []).filter((r) => r.url).map((r) => (
                <a key={r.url} href={r.url} target="_blank" rel="noreferrer" style={{ marginLeft: 8 }}>
                  öffnen
                </a>
              ))}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
