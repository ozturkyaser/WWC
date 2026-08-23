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
  const adminHref = st.admin_login_url
    || (preview ? `${preview.replace(/\/$/, "")}/wp-admin/` : null);

  return (
    <div className="dev-shell">
      <header className="dev-bar">
        <div>
          <div className="dev-brand">WWC Dev</div>
          <h1>{data.site.name}</h1>
          <p className="muted" style={{ margin: 0 }}>
            Development-Umgebung
            {st.exists ? " · bereit" : " · nicht aktiv"}
          </p>
        </div>
        <a className="btn secondary" href={`/sites/${data.site.id}`}>
          Zur Site
        </a>
      </header>

      {error && <p className="muted" style={{ margin: "0 0 12px" }}>{error}</p>}

      {st.exists ? (
        <div className="dev-launch">
          <p style={{ marginTop: 0 }}>
            Die Kundenseite lässt sich nicht im Portal-Fenster einbetten
            (Browser und Hosting blockieren das). Öffne Staging immer in einem neuen Tab.
          </p>
          {preview && (
            <p className="muted" style={{ marginTop: 0 }}>
              Staging-URL:{" "}
              <a href={preview} target="_blank" rel="noreferrer">{preview}</a>
            </p>
          )}
          <div className="row" style={{ marginBottom: 16 }}>
            {adminHref && (
              <a className="btn" href={adminHref} target="_blank" rel="noreferrer">
                WP-Admin öffnen
              </a>
            )}
            {preview && (
              <a className="btn secondary" href={preview} target="_blank" rel="noreferrer">
                Frontend prüfen
              </a>
            )}
            <button className="btn secondary" type="button" disabled={busy} onClick={grantAdmin}>
              Admin-Zugang erneuern
            </button>
          </div>
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
        </div>
      ) : (
        <div className="dev-launch">
          <p>Keine aktive Staging-Umgebung. Erzeuge sie auf der Site-Detailseite.</p>
          <a className="btn" href={`/sites/${data.site.id}`}>Staging erzeugen</a>
        </div>
      )}
    </div>
  );
}
