"use client";

import { FormEvent, useEffect, useState } from "react";
import { Shell } from "@/components/Shell";
import { Flash, PageHeader, Section } from "@/components/ui";
import { api } from "@/lib/api";

type Entry = {
  id: string;
  minutes: number;
  description?: string;
  occurred_at: string;
  project?: { id: string; name: string };
  site?: { id: string; name: string };
  user?: { name: string };
};
type Usage = { project_id: string; name: string; included_hours: number; used_hours: number; overage_hours: number };
type Project = { id: string; name: string };

export default function TimePage() {
  const [entries, setEntries] = useState<Entry[]>([]);
  const [usage, setUsage] = useState<Usage[]>([]);
  const [projects, setProjects] = useState<Project[]>([]);
  const [projectId, setProjectId] = useState("");
  const [minutes, setMinutes] = useState(30);
  const [description, setDescription] = useState("");
  const [msg, setMsg] = useState("");
  const [tone, setTone] = useState<"info" | "ok" | "error">("info");

  async function load() {
    const [t, p] = await Promise.all([
      api<{ data: Entry[]; usage: Usage[] }>("/time-entries"),
      api<{ data: Project[] }>("/projects"),
    ]);
    setEntries(t.data);
    setUsage(t.usage);
    setProjects(p.data);
    if (!projectId && p.data[0]) setProjectId(p.data[0].id);
  }

  useEffect(() => {
    load().catch((e) => {
      setTone("error");
      setMsg(e.message);
    });
  }, []);

  async function add(e: FormEvent) {
    e.preventDefault();
    await api("/time-entries", {
      method: "POST",
      body: JSON.stringify({ project_id: projectId || null, minutes, description }),
    });
    setDescription("");
    setTone("ok");
    setMsg("Zeit gebucht");
    await load();
  }

  return (
    <Shell>
      <PageHeader title="Stunden" subtitle="Kontingent je Projekt – Überziehung erscheint auf der nächsten Rechnung." />
      <Flash tone={tone}>{msg}</Flash>

      <Section title="Zeit buchen">
        <form className="row" onSubmit={add} style={{ gap: 8, flexWrap: "wrap" }}>
          <select value={projectId} onChange={(e) => setProjectId(e.target.value)}>
            {projects.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
          </select>
          <input type="number" min={5} step={5} value={minutes} onChange={(e) => setMinutes(Number(e.target.value))} />
          <input placeholder="Was wurde gemacht?" value={description} onChange={(e) => setDescription(e.target.value)} />
          <button className="btn" type="submit">Buchen</button>
        </form>
      </Section>

      <Section title="Verbrauch diesen Monat">
        <table className="table">
          <thead><tr><th>Projekt</th><th>Enthalten</th><th>Verbraucht</th><th>Mehrarbeit</th></tr></thead>
          <tbody>
            {usage.map((u) => (
              <tr key={u.project_id}>
                <td>{u.name}</td>
                <td>{u.included_hours} h</td>
                <td>{u.used_hours} h</td>
                <td>{u.overage_hours > 0 ? <span className="badge warn">{u.overage_hours} h</span> : "–"}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </Section>

      <div className="surface">
        <table className="table">
          <thead><tr><th>Wann</th><th>Projekt</th><th>Min</th><th>Beschreibung</th><th></th></tr></thead>
          <tbody>
            {entries.map((e) => (
              <tr key={e.id}>
                <td>{new Date(e.occurred_at).toLocaleString("de-DE")}</td>
                <td>{e.project?.name || "–"}</td>
                <td>{e.minutes}</td>
                <td>{e.description}</td>
                <td>
                  <button className="btn danger sm" type="button" onClick={async () => {
                    await api(`/time-entries/${e.id}`, { method: "DELETE" });
                    await load();
                  }}>Löschen</button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </Shell>
  );
}
