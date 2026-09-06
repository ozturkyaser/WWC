"use client";

import { useEffect, useState } from "react";
import { Flash, Section } from "@/components/ui";
import { api } from "@/lib/api";

export function McpConnect() {
  const [exists, setExists] = useState(false);
  const [snippet, setSnippet] = useState("");
  const [token, setToken] = useState("");
  const [msg, setMsg] = useState("");
  const [tone, setTone] = useState<"info" | "ok" | "error">("info");
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    api<{ exists: boolean }>("/auth/mcp-token")
      .then((r) => setExists(r.exists))
      .catch(() => setExists(false));
  }, []);

  async function issue() {
    setBusy(true);
    setMsg("");
    try {
      const res = await api<{ token: string; cursor_config: unknown }>("/auth/mcp-token", { method: "POST" });
      const text = JSON.stringify(res.cursor_config, null, 2);
      setSnippet(text);
      setToken(res.token);
      setExists(true);
      setTone("ok");
      setMsg("Verbindungscode erzeugt. Nur diesmal sichtbar – in Cursor unter MCP einfügen.");
    } catch (e) {
      setTone("error");
      setMsg(e instanceof Error ? e.message : "Konnte den Code nicht erzeugen.");
    } finally {
      setBusy(false);
    }
  }

  async function copy(text: string, label: string) {
    await navigator.clipboard.writeText(text);
    setTone("ok");
    setMsg(`${label} kopiert.`);
  }

  return (
    <Section
      title="Cursor verbinden"
      note="Kein Pairing-Code und nicht im Clone-WordPress. Dieser Token gehört in deine lokale Cursor-Config."
    >
      <Flash tone={tone}>{msg}</Flash>
      <p className="muted" style={{ marginTop: 0 }}>
        {exists
          ? "Es gibt bereits einen MCP-Zugang. Neu erzeugen ersetzt den alten Code."
          : "Noch kein MCP-Zugang. Ein Klick erzeugt den Code für Cursor."}
      </p>
      <div className="row" style={{ marginBottom: snippet ? 12 : 0 }}>
        <button className="btn" type="button" disabled={busy} onClick={issue}>
          {exists ? "Neuen Verbindungscode erzeugen" : "Verbindungscode erzeugen"}
        </button>
      </div>
      {token && (
        <div className="field">
          <label>Token</label>
          <div className="row">
            <input readOnly value={token} />
            <button className="btn secondary" type="button" onClick={() => copy(token, "Token")}>
              Kopieren
            </button>
          </div>
        </div>
      )}
      {snippet && (
        <div className="field" style={{ marginBottom: 0 }}>
          <label>Cursor-Config (mcp.json)</label>
          <textarea readOnly rows={14} value={snippet} style={{ fontFamily: "ui-monospace, monospace", fontSize: 12 }} />
          <button className="btn secondary" type="button" onClick={() => copy(snippet, "Cursor-Config")}>
            Config kopieren
          </button>
        </div>
      )}
    </Section>
  );
}
