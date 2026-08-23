"use client";

import { useEffect, useState } from "react";
import { useParams } from "next/navigation";
import { api } from "@/lib/api";
import { portalApexOrigin } from "@/lib/staging";

type StagingPayload = {
  exists: boolean;
  slug?: string | null;
  portal_url?: string | null;
  preview_url?: string | null;
  admin_url?: string | null;
  admin_login_url?: string | null;
  access?: { username?: string; password?: string | null; expires_at?: string | null } | null;
};

type Response = {
  data: {
    site: { id: string; name: string; url: string; status: string };
    staging: StagingPayload;
  };
};

function readToken() {
  if (typeof window === "undefined") return null;
  return localStorage.getItem("wwc_token");
}

export default function DevStagingPage() {
  const params = useParams<{ slug: string }>();
  const [data, setData] = useState<Response["data"] | null>(null);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  async function load() {
    const res = await api<Response>(`/staging/${params.slug}`);
    setData(res.data);
  }

  useEffect(() => {
    if (!readToken()) {
      const apex = portalApexOrigin();
      const next = `${apex}/dev/${params.slug}`;
      // Always land on apex /login so localStorage from the main portal is available
      window.location.replace(`${apex}/login?next=${encodeURIComponent(next)}`);
      return;
    }
    load().catch((e) => setError(e instanceof Error ? e.message : "Fehler"));
    const t = setInterval(() => load().catch(() => undefined), 8000);
    return () => clearInterval(t);
  }, [params.slug]);

  async function grantAdmin() {
    if (!data?.site.id) return;
    setBusy(true);
    setError("");
    try {
      await api(`/sites/${data.site.id}/staging/grant-admin`, { method: "POST" });
      setError("Admin-Zugang wird erneuert…");
      await load();
    } catch (e) {
      setError(e instanceof Error ? e.message : "Fehler");
    } finally {
      setBusy(false);
    }
  }

  if (error && !data) {
    return (
      <div className="dev-shell">
        <p className="muted">{error}</p>
        <a href="/" className="btn secondary">Zum Portal</a>
      </div>
    );
  }

  if (!data) {
    return <div className="dev-shell"><p className="muted">Lade Dev-Umgebung…</p></div>;
  }

  const st = data.staging;
  const preview = st.preview_url || st.admin_url;

  return (
    <div className="dev-shell">
      <header className="dev-bar">
        <div>
          <div className="dev-brand">WWC Dev</div>
          <h1>{data.site.name}</h1>
          <p className="muted" style={{ margin: 0 }}>
            Dev <code>/dev/{st.slug}</code>
            {st.exists ? " · live" : " · nicht aktiv"}
          </p>
        </div>
        <div className="row">
          {preview && (
            <a className="btn secondary" href={preview} target="_blank" rel="noreferrer">
              Frontend prüfen
            </a>
          )}
          {st.admin_login_url && (
            <a className="btn" href={st.admin_login_url} target="_blank" rel="noreferrer">
              WP-Admin öffnen
            </a>
          )}
          <button className="btn secondary" type="button" disabled={busy || !st.exists} onClick={grantAdmin}>
            Admin-Zugang erneuern
          </button>
          <a className="btn secondary" href={`/sites/${data.site.id}`}>
            Zur Site
          </a>
        </div>
      </header>

      {error && <p className="muted" style={{ margin: "0 0 12px" }}>{error}</p>}

      {st.access && (
        <div className="dev-creds">
          <span>Login: <strong>{st.access.username}</strong></span>
          {st.access.password && (
            <span>Passwort: <code>{st.access.password}</code></span>
          )}
          {st.access.expires_at && (
            <span className="muted">gültig bis {new Date(st.access.expires_at).toLocaleString("de-DE")}</span>
          )}
        </div>
      )}

      <p className="muted" style={{ margin: "0 0 10px", fontSize: "0.85rem" }}>
        Login und WP-Admin nur über <strong>WP-Admin öffnen</strong> (neuer Tab). Im Iframe blockiert der Browser Cookies.
      </p>
      <div className="dev-frame-wrap">
        {st.exists && preview ? (
          <iframe title={`Staging ${data.site.name}`} src={preview} className="dev-frame" />
        ) : (
          <div className="dev-fallback">
            <p>Keine aktive Staging-Umgebung. Erzeuge sie auf der Site-Detailseite.</p>
            <a className="btn" href={`/sites/${data.site.id}`}>Staging erzeugen</a>
          </div>
        )}
      </div>
    </div>
  );
}
