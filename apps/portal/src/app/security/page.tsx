"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { Shell } from "@/components/Shell";
import { Empty, Flash, PageHeader, Section, Tabs } from "@/components/ui";
import { api } from "@/lib/api";

type Finding = {
  id: string;
  status: string;
  priority_score?: number;
  installed_version?: string;
  site?: { id: string; name: string; url: string };
  vulnerability?: {
    title: string;
    severity: string;
    slug: string;
    fixed_in?: string;
    component_type: string;
    cvss?: number;
    is_exploited?: boolean;
    url?: string;
    priority_score?: number;
  };
};

type FailedLogin = {
  id: string;
  title: string;
  occurred_at: string;
  username?: string;
  password_length?: number;
  password_present?: boolean;
  password_sha256?: string;
  url?: string;
  ip?: string;
  user_agent?: string;
  referer?: string;
  site?: { id: string; name: string; url: string };
};

export default function SecurityPage() {
  const [tab, setTab] = useState("findings");
  const [findings, setFindings] = useState<Finding[]>([]);
  const [logins, setLogins] = useState<FailedLogin[]>([]);
  const [msg, setMsg] = useState("");
  const [tone, setTone] = useState<"info" | "ok" | "error">("info");
  const [busy, setBusy] = useState(false);

  async function load() {
    const [f, l] = await Promise.all([
      api<{ data: Finding[] }>("/security/findings"),
      api<{ data: FailedLogin[] }>("/security/failed-logins"),
    ]);
    setFindings(f.data);
    setLogins(l.data);
  }

  useEffect(() => {
    load().catch((e) => {
      setTone("error");
      setMsg(e.message);
    });
  }, []);

  async function sync() {
    setBusy(true);
    try {
      const res = await api<{ synced: number; patchstack?: { unique?: number } }>("/security/sync", {
        method: "POST",
        body: JSON.stringify({ pages: 100, full: false }),
      });
      setTone("ok");
      setMsg(
        `${res.synced} Advisories synchronisiert` +
          (res.patchstack?.unique != null ? ` (Patchstack ≈ ${res.patchstack.unique} unique)` : "")
      );
      await load();
    } catch (e: unknown) {
      setTone("error");
      setMsg(e instanceof Error ? e.message : "Sync fehlgeschlagen");
    } finally {
      setBusy(false);
    }
  }

  async function ignore(id: string) {
    await api(`/security/findings/${id}/ignore`, { method: "POST" });
    await load();
  }

  async function autoFix(siteId: string) {
    const res = await api<{ skipped?: boolean; reason?: string; jobs?: string[] }>(
      `/security/sites/${siteId}/auto-fix`,
      { method: "POST" }
    );
    setTone("info");
    setMsg(res.skipped ? `Übersprungen: ${res.reason}` : `Auto-Fix Jobs: ${(res.jobs || []).length}`);
  }

  return (
    <Shell>
      <PageHeader
        title="Security"
        subtitle="Patchstack-Advisories, priorisierte Findings und Failed-Login-Forensik."
        actions={
          <button className="btn secondary" type="button" disabled={busy} onClick={sync}>
            {busy ? "Sync…" : "Patchstack sync"}
          </button>
        }
      />
      <Flash tone={tone}>{msg}</Flash>

      <Tabs
        value={tab}
        onChange={setTab}
        items={[
          { id: "findings", label: `Findings${findings.length ? ` (${findings.length})` : ""}` },
          { id: "logins", label: `Failed Logins${logins.length ? ` (${logins.length})` : ""}` },
        ]}
      />

      {tab === "findings" && (
        <div className="surface">
          {findings.length === 0 ? (
            <Empty title="Keine Findings" text="Alles ruhig – oder Sites noch nicht gescannt / Advisories syncen." />
          ) : (
            <table className="table">
              <thead>
                <tr>
                  <th>Prio</th>
                  <th>Site</th>
                  <th>Finding</th>
                  <th>Severity</th>
                  <th>Fix</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {findings.map((f) => (
                  <tr key={f.id}>
                    <td className="muted">{f.priority_score ?? f.vulnerability?.priority_score ?? "–"}</td>
                    <td>
                      {f.site ? (
                        <Link href={`/sites/${f.site.id}`}>{f.site.name}</Link>
                      ) : (
                        "–"
                      )}
                    </td>
                    <td>
                      <div className="cell-title">
                        {f.vulnerability?.url ? (
                          <a href={f.vulnerability.url} target="_blank" rel="noreferrer">
                            {f.vulnerability?.title}
                          </a>
                        ) : (
                          f.vulnerability?.title
                        )}
                      </div>
                      <div className="cell-sub">
                        {f.vulnerability?.component_type}/{f.vulnerability?.slug} @ {f.installed_version}
                        {f.vulnerability?.is_exploited ? " · exploited" : ""}
                        {f.vulnerability?.cvss != null ? ` · CVSS ${f.vulnerability.cvss}` : ""}
                      </div>
                    </td>
                    <td>
                      <span className={`badge ${f.vulnerability?.severity}`}>{f.vulnerability?.severity}</span>
                    </td>
                    <td className="muted">{f.vulnerability?.fixed_in || "–"}</td>
                    <td>
                      {f.status === "open" && f.site ? (
                        <div className="action-menu">
                          <button className="btn secondary sm" type="button" onClick={() => autoFix(f.site!.id)}>
                            Auto-Fix
                          </button>
                          <button className="btn ghost-btn sm" type="button" onClick={() => ignore(f.id)}>
                            Ignorieren
                          </button>
                        </div>
                      ) : (
                        <span className="badge">{f.status}</span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      )}

      {tab === "logins" && (
        <Section title="Fehlgeschlagene Logins" note="URL, User, Passwort-Hash/Länge, IP – Passwort verschlüsselt gespeichert">
          {logins.length === 0 ? (
            <Empty title="Keine Failed Logins" text="Sobald Angriffe oder Tippfehler ankommen, erscheinen sie hier." />
          ) : (
            <table className="table">
              <thead>
                <tr>
                  <th>Zeit</th>
                  <th>Site</th>
                  <th>User</th>
                  <th>Pass</th>
                  <th>URL</th>
                  <th>IP</th>
                </tr>
              </thead>
              <tbody>
                {logins.map((l) => (
                  <tr key={l.id}>
                    <td className="muted">{new Date(l.occurred_at).toLocaleString("de-DE")}</td>
                    <td>{l.site?.name || "–"}</td>
                    <td>
                      <code>{l.username || "–"}</code>
                    </td>
                    <td className="muted">
                      {l.password_present
                        ? `${l.password_length ?? "?"} Zeichen · sha256 ${l.password_sha256?.slice(0, 10) || "…"}…`
                        : "–"}
                    </td>
                    <td>
                      <div className="cell-sub" style={{ maxWidth: 280, wordBreak: "break-all" }}>
                        {l.url || "–"}
                      </div>
                    </td>
                    <td>
                      <code>{l.ip || "–"}</code>
                      {l.user_agent && (
                        <div className="cell-sub" style={{ maxWidth: 180 }}>
                          {l.user_agent.slice(0, 60)}
                        </div>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </Section>
      )}
    </Shell>
  );
}
