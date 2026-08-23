"use client";

import { useState } from "react";
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

type Draft = {
  prompt?: string;
  summary?: string;
  status?: string;
  ops?: Array<Record<string, unknown>>;
  error?: string | null;
  dev_results?: Array<{ ok?: boolean; error?: string; url?: string }>;
};

type Studio = {
  intel?: Intel | null;
  intel_source?: string | null;
  scanned_at?: string | null;
  draft?: Draft | null;
  clone_ready?: boolean;
  clone_url?: string | null;
};

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
  const intel = studio.intel;
  const draft = studio.draft;

  async function run(path: string, body?: unknown) {
    setBusy(true);
    setMsg(null);
    try {
      const res = await api<{ data: Studio }>(`/sites/${siteId}/content-studio/${path}`, {
        method: "POST",
        body: body !== undefined ? JSON.stringify(body) : undefined,
      });
      setStudio(res.data);
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
      setMsg("Logo liegt in der Dev-Kopie. Jetzt „In Dev anwenden“.");
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
        Die KI scannt die isolierte Umgebung (Theme, Plugins, Editor, Seiten) und setzt Änderungen
        zuerst dort um. Live erst nach Freigabe.
      </p>
      {!studio.clone_ready && (
        <p className="error">Zuerst im Tab Development die isolierte Umgebung auf dem WWC-Server erstellen.</p>
      )}
      {msg && <p className={msg.includes("fehl") || msg.includes("Fehler") ? "error" : "muted"}>{msg}</p>}

      <div className="row" style={{ marginBottom: 14 }}>
        <button className="btn" type="button" disabled={busy} onClick={() => run("scan")}>
          Website scannen
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
        />
      </div>
      <button className="btn" type="button" disabled={busy || !prompt.trim() || !intel} onClick={() => run("plan", { prompt })}>
        Plan erzeugen
      </button>

      {draft?.summary && (
        <div style={{ marginTop: 16 }}>
          <p>
            <strong>Plan</strong>{" "}
            {draft.status === "applied_dev" && <span className="badge completed">in Dev</span>}
            {draft.status === "promoted" && <span className="badge completed">live</span>}
            {draft.status === "failed" && <span className="badge failed">fehlgeschlagen</span>}
            {draft.status === "promoting" && <span className="badge running">wird live übernommen…</span>}
          </p>
          <p className="muted">{draft.summary}</p>
          {(draft.ops || []).map((op, i) => (
            <div key={i} className="cell-sub" style={{ fontSize: "0.82rem" }}>
              {(op.op as string) || "op"}
              {op.title ? `: ${String(op.title)}` : ""}
              {op.id ? ` #${String(op.id)}` : ""}
              {op.key ? ` ${String(op.key)}` : ""}
            </div>
          ))}
          {draft.error && <div className="error">{draft.error}</div>}
          <div className="row" style={{ marginTop: 12 }}>
            <button
              className="btn"
              type="button"
              disabled={busy || !studio.clone_ready || draft.status === "promoting"}
              onClick={() => run("apply-dev")}
            >
              In Dev anwenden
            </button>
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
    </div>
  );
}
