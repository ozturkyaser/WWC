"use client";

import { FormEvent, useState } from "react";
import { useRouter } from "next/navigation";
import { api, setToken } from "@/lib/api";

export default function LoginPage() {
  const router = useRouter();
  const [email, setEmail] = useState("admin@wwc.local");
  const [password, setPassword] = useState("password");
  const [code, setCode] = useState("");
  const [needsCode, setNeedsCode] = useState(false);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  const [inviteToken] = useState(() =>
    typeof window !== "undefined" ? new URLSearchParams(window.location.search).get("invite") || "" : ""
  );
  const [inviteName, setInviteName] = useState("");

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setLoading(true);
    setError("");
    try {
      if (inviteToken) {
        const res = await api<{ token: string }>("/auth/invite/accept", {
          method: "POST",
          auth: false,
          body: JSON.stringify({ token: inviteToken, name: inviteName || email, password }),
        });
        setToken(res.token);
        router.push("/dashboard");
        return;
      }
      const res = await api<{ token?: string; requires_2fa?: boolean }>("/auth/login", {
        method: "POST",
        auth: false,
        body: JSON.stringify({ email, password, ...(code ? { code } : {}) }),
      });
      if (res.requires_2fa && !res.token) {
        setNeedsCode(true);
        setLoading(false);
        return;
      }
      if (!res.token) throw new Error("Login fehlgeschlagen");
      setToken(res.token);
      const next = new URLSearchParams(window.location.search).get("next");
      if (next) {
        try {
          const target = new URL(next, window.location.origin);
          const host = target.hostname;
          if (host === "localhost" || host.endsWith(".localhost") || target.origin === window.location.origin) {
            window.location.href = target.toString();
            return;
          }
        } catch {
          /* fall through */
        }
        if (next.startsWith("/")) {
          router.push(next);
          return;
        }
      }
      router.push("/dashboard");
    } catch (err) {
      setError(err instanceof Error ? err.message : "Login fehlgeschlagen");
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="auth-wrap">
      <form className="auth-card" onSubmit={onSubmit}>
        <h1>WWC</h1>
        <p className="muted">{inviteToken ? "Einladung annehmen" : "Wartungsportal anmelden"}</p>
        {inviteToken && (
          <div className="field" style={{ marginTop: 24 }}>
            <label htmlFor="name">Name</label>
            <input id="name" value={inviteName} onChange={(e) => setInviteName(e.target.value)} required />
          </div>
        )}
        <div className="field" style={{ marginTop: inviteToken ? 0 : 24 }}>
          <label htmlFor="email">E-Mail</label>
          <input id="email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} required />
        </div>
        <div className="field">
          <label htmlFor="password">Passwort</label>
          <input id="password" type="password" value={password} onChange={(e) => setPassword(e.target.value)} required />
        </div>
        {needsCode && (
          <div className="field">
            <label htmlFor="code">2FA-Code</label>
            <input
              id="code"
              inputMode="numeric"
              autoComplete="one-time-code"
              placeholder="6-stelliger Code oder Recovery-Code"
              value={code}
              onChange={(e) => setCode(e.target.value)}
              autoFocus
              required
            />
            <p className="muted" style={{ marginTop: 4, fontSize: 12 }}>
              Code aus deiner Authenticator-App eingeben.
            </p>
          </div>
        )}
        {error && <p className="error">{error}</p>}
        <button className="btn" style={{ width: "100%", marginTop: 8 }} disabled={loading} type="submit">
          {loading ? "…" : "Anmelden"}
        </button>
      </form>
    </div>
  );
}
