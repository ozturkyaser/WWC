"use client";

import { FormEvent, useEffect, useState } from "react";
import { Shell } from "@/components/Shell";
import { Flash, PageHeader, Section } from "@/components/ui";
import { api } from "@/lib/api";

type Member = { id: number; user_id: number; role: string; name?: string; email?: string };
type Invite = { id: string; email: string; role: string; expires_at: string };

export default function TeamPage() {
  const [members, setMembers] = useState<Member[]>([]);
  const [invites, setInvites] = useState<Invite[]>([]);
  const [email, setEmail] = useState("");
  const [role, setRole] = useState("technician");
  const [acceptUrl, setAcceptUrl] = useState("");
  const [msg, setMsg] = useState("");
  const [tone, setTone] = useState<"info" | "ok" | "error">("info");

  async function load() {
    const res = await api<{ data: Member[]; invites: Invite[] }>("/team");
    setMembers(res.data);
    setInvites(res.invites);
  }

  useEffect(() => {
    load().catch((e) => {
      setTone("error");
      setMsg(e.message);
    });
  }, []);

  async function invite(e: FormEvent) {
    e.preventDefault();
    try {
      const res = await api<{ accept_url: string }>("/team/invites", {
        method: "POST",
        body: JSON.stringify({ email, role }),
      });
      setAcceptUrl(res.accept_url);
      setEmail("");
      setTone("ok");
      setMsg("Einladung erstellt – Link an die Person schicken.");
      await load();
    } catch (err) {
      setTone("error");
      setMsg(err instanceof Error ? err.message : "Fehler");
    }
  }

  return (
    <Shell>
      <PageHeader title="Team" subtitle="Kollegen einladen und Rollen setzen (Inhaber, Admin, Techniker, Nur-Lesen)." />
      <Flash tone={tone}>{msg}</Flash>
      {acceptUrl && (
        <p className="muted">
          Einladungslink: <a href={acceptUrl}>{acceptUrl}</a>
        </p>
      )}

      <Section title="Einladen">
        <form className="row" onSubmit={invite} style={{ gap: 8, flexWrap: "wrap" }}>
          <input type="email" required placeholder="kollege@firma.de" value={email} onChange={(e) => setEmail(e.target.value)} />
          <select value={role} onChange={(e) => setRole(e.target.value)}>
            <option value="technician">Techniker</option>
            <option value="admin">Admin</option>
            <option value="viewer">Nur lesen</option>
          </select>
          <button className="btn" type="submit">Einladen</button>
        </form>
      </Section>

      <div className="surface">
        <table className="table">
          <thead><tr><th>Name</th><th>E-Mail</th><th>Rolle</th><th></th></tr></thead>
          <tbody>
            {members.map((m) => (
              <tr key={m.id}>
                <td>{m.name}</td>
                <td>{m.email}</td>
                <td>
                  <select
                    value={m.role}
                    onChange={async (e) => {
                      await api(`/team/members/${m.id}`, { method: "PUT", body: JSON.stringify({ role: e.target.value }) });
                      await load();
                    }}
                  >
                    <option value="owner">Inhaber</option>
                    <option value="admin">Admin</option>
                    <option value="technician">Techniker</option>
                    <option value="viewer">Nur lesen</option>
                  </select>
                </td>
                <td>
                  {m.role !== "owner" && (
                    <button className="btn danger sm" type="button" onClick={async () => {
                      await api(`/team/members/${m.id}`, { method: "DELETE" });
                      await load();
                    }}>Entfernen</button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {invites.length > 0 && (
        <Section title="Offene Einladungen">
          {invites.map((i) => (
            <div className="row" key={i.id} style={{ justifyContent: "space-between" }}>
              <span>{i.email} · {i.role} · bis {new Date(i.expires_at).toLocaleDateString("de-DE")}</span>
              <button className="btn secondary sm" type="button" onClick={async () => {
                await api(`/team/invites/${i.id}`, { method: "DELETE" });
                await load();
              }}>Zurückziehen</button>
            </div>
          ))}
        </Section>
      )}
    </Shell>
  );
}
