"use client";

import { api, downloadPlugin } from "@/lib/api";
import { useEffect, useState } from "react";

export type InstallInfo = {
  pairing_code: string;
  expires_at?: string;
  api_url: string;
  site_id?: string;
  site_url?: string;
  site_name?: string;
  steps?: string[];
};

type ConnectionInfo = {
  recommended_api_url: string;
  suggested_api_urls: string[];
  tips: string[];
};

export function InstallWizard({ install }: { install: InstallInfo }) {
  const [msg, setMsg] = useState("");
  const [busy, setBusy] = useState(false);
  const [connection, setConnection] = useState<ConnectionInfo | null>(null);

  useEffect(() => {
    api<ConnectionInfo>("/connection-info", { auth: false })
      .then(setConnection)
      .catch(() => undefined);
  }, []);

  const recommended = connection?.recommended_api_url || install.api_url.replace("localhost", "192.168.1.30").replace("127.0.0.1", "192.168.1.30");

  async function handleDownload() {
    setBusy(true);
    setMsg("");
    try {
      await downloadPlugin();
      setMsg("Download gestartet – ZIP in WordPress hochladen.");
    } catch (e) {
      setMsg(e instanceof Error ? e.message : "Download fehlgeschlagen");
    } finally {
      setBusy(false);
    }
  }

  async function copy(text: string, label: string) {
    await navigator.clipboard.writeText(text);
    setMsg(`${label} kopiert`);
  }

  return (
    <div className="surface surface-pad" style={{ borderColor: "rgba(196,163,90,0.35)" }}>
      <h2 style={{ marginTop: 0, fontSize: "1.15rem" }}>
        Plugin verbinden
      </h2>
      {install.site_name && (
        <p className="muted" style={{ marginTop: 0 }}>
          {install.site_name}{install.site_url ? ` · ${install.site_url}` : ""}
        </p>
      )}

      <div className="flash" style={{ marginBottom: 12 }}>
        <strong style={{ color: "var(--text)" }}>API-URL für WordPress</strong>
        <p className="muted" style={{ margin: "6px 0 0" }}>
          Nicht <code>localhost</code> nutzen – LAN-IP oder Tunnel-URL:
        </p>
        <div className="row" style={{ marginTop: 8 }}>
          <input readOnly value={recommended} style={{ flex: 1, color: "var(--accent-2)" }} />
          <button className="btn sm" type="button" onClick={() => copy(recommended, "Empfohlene API-URL")}>
            Kopieren
          </button>
        </div>
      </div>

      <ol style={{ paddingLeft: 18, color: "var(--muted)", lineHeight: 1.6 }}>
        {(install.steps || [
          "Plugin-ZIP herunterladen und in WordPress hochladen/aktivieren",
          "Einstellungen → WWC Agent öffnen",
          "API-URL (LAN-IP oben) + Pairing-Code eintragen",
          "Verbinden & synchronisieren",
        ]).map((step) => (
          <li key={step}>{step}</li>
        ))}
      </ol>

      <div className="field">
        <label>Pairing-Code (15 Min gültig)</label>
        <div className="row">
          <input
            readOnly
            value={install.pairing_code}
            style={{ flex: 1, fontFamily: "monospace", letterSpacing: "0.06em", color: "var(--accent-2)" }}
          />
          <button className="btn secondary" type="button" onClick={() => copy(install.pairing_code, "Pairing-Code")}>
            Kopieren
          </button>
        </div>
        {install.expires_at && (
          <span className="muted" style={{ fontSize: "0.8rem" }}>
            Gültig bis {new Date(install.expires_at).toLocaleString("de-DE")}
          </span>
        )}
      </div>

      {connection && (
        <details style={{ marginBottom: 12 }}>
          <summary className="muted">Weitere API-URL-Varianten</summary>
          <ul className="muted">
            {connection.suggested_api_urls.map((u) => (
              <li key={u}>
                <button className="btn secondary" type="button" style={{ margin: "4px 0" }} onClick={() => copy(u, "API-URL")}>
                  {u}
                </button>
              </li>
            ))}
          </ul>
          {connection.tips.map((t) => (
            <p key={t} className="muted" style={{ fontSize: "0.85rem" }}>{t}</p>
          ))}
        </details>
      )}

      <div className="row">
        <button className="btn" type="button" disabled={busy} onClick={handleDownload}>
          {busy ? "…" : "WWC-Agent Plugin (ZIP) herunterladen"}
        </button>
      </div>
      {msg && <p className="muted" style={{ marginBottom: 0 }}>{msg}</p>}
    </div>
  );
}
