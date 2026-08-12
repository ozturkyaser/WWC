"use client";

import { FormEvent, useState } from "react";
import { useRouter } from "next/navigation";
import { api, setToken } from "@/lib/api";

export default function LoginPage() {
  const router = useRouter();
  const [email, setEmail] = useState("admin@wwc.local");
  const [password, setPassword] = useState("password");
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setLoading(true);
    setError("");
    try {
      const res = await api<{ token: string }>("/auth/login", {
        method: "POST",
        auth: false,
        body: JSON.stringify({ email, password }),
      });
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
        <p className="muted">Wartungsportal anmelden</p>
        <div className="field" style={{ marginTop: 24 }}>
          <label htmlFor="email">E-Mail</label>
          <input id="email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} required />
        </div>
        <div className="field">
          <label htmlFor="password">Passwort</label>
          <input id="password" type="password" value={password} onChange={(e) => setPassword(e.target.value)} required />
        </div>
        {error && <p className="error">{error}</p>}
        <button className="btn" style={{ width: "100%", marginTop: 8 }} disabled={loading} type="submit">
          {loading ? "…" : "Anmelden"}
        </button>
      </form>
    </div>
  );
}
