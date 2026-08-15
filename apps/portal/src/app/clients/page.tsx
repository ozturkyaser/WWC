"use client";

import { FormEvent, useEffect, useState } from "react";
import Link from "next/link";
import { Shell } from "@/components/Shell";
import { Drawer, Empty, Flash, PageHeader } from "@/components/ui";
import { api } from "@/lib/api";

type Client = {
  id: string;
  name: string;
  email?: string;
  company?: string;
  phone?: string;
  notes?: string;
  contract_until?: string | null;
  sla_response_hours?: number | null;
  projects_count?: number;
};

const empty = { name: "", email: "", company: "", phone: "", notes: "", contract_until: "", sla_response_hours: "" };

export default function ClientsPage() {
  const [clients, setClients] = useState<Client[]>([]);
  const [form, setForm] = useState(empty);
  const [editId, setEditId] = useState<string | null>(null);
  const [open, setOpen] = useState(false);
  const [msg, setMsg] = useState("");
  const [tone, setTone] = useState<"info" | "ok" | "error">("info");

  async function load() {
    const res = await api<{ data: Client[] }>("/clients");
    setClients(res.data);
  }

  useEffect(() => {
    load().catch((e) => {
      setTone("error");
      setMsg(e.message);
    });
  }, []);

  async function save(e: FormEvent) {
    e.preventDefault();
    const body = {
      ...form,
      sla_response_hours: form.sla_response_hours ? Number(form.sla_response_hours) : null,
      contract_until: form.contract_until || null,
    };
    if (editId) await api(`/clients/${editId}`, { method: "PUT", body: JSON.stringify(body) });
    else await api("/clients", { method: "POST", body: JSON.stringify(body) });
    setOpen(false);
    setForm(empty);
    setEditId(null);
    setTone("ok");
    setMsg("Gespeichert");
    await load();
  }

  return (
    <Shell>
      <PageHeader
        title="Kunden"
        subtitle="Akte, Vertragslaufzeit und SLA – nicht nur Rechnungsadresse."
        actions={<button className="btn" type="button" onClick={() => { setEditId(null); setForm(empty); setOpen(true); }}>Kunde anlegen</button>}
      />
      <Flash tone={tone}>{msg}</Flash>
      <div className="surface">
        {clients.length === 0 ? (
          <Empty title="Keine Kunden" text="Lege einen Kunden an oder nutze den Projekt-Wizard." />
        ) : (
          <table className="table">
            <thead><tr><th>Kunde</th><th>Kontakt</th><th>SLA / Vertrag</th><th></th></tr></thead>
            <tbody>
              {clients.map((c) => (
                <tr key={c.id}>
                  <td>
                    <div className="cell-title">{c.name}</div>
                    <div className="cell-sub">{c.company || "–"} · {c.projects_count ?? 0} Projekte</div>
                  </td>
                  <td>
                    <div>{c.email || "–"}</div>
                    <div className="cell-sub">{c.phone}</div>
                  </td>
                  <td>
                    <div>{c.sla_response_hours ? `${c.sla_response_hours} h Reaktion` : "kein SLA"}</div>
                    <div className="cell-sub">{c.contract_until ? `bis ${c.contract_until}` : ""}</div>
                  </td>
                  <td>
                    <button className="btn secondary sm" type="button" onClick={() => {
                      setEditId(c.id);
                      setForm({
                        name: c.name,
                        email: c.email || "",
                        company: c.company || "",
                        phone: c.phone || "",
                        notes: c.notes || "",
                        contract_until: c.contract_until || "",
                        sla_response_hours: c.sla_response_hours ? String(c.sla_response_hours) : "",
                      });
                      setOpen(true);
                    }}>Bearbeiten</button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
      <p className="muted" style={{ marginTop: 12 }}>
        Projekte zu einem Kunden legst du unter <Link href="/projects">Projekte</Link> an.
      </p>

      <Drawer open={open} title={editId ? "Kunde bearbeiten" : "Neuer Kunde"} onClose={() => setOpen(false)}>
        <form onSubmit={save}>
          <div className="field"><label>Name</label><input required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></div>
          <div className="field"><label>Firma</label><input value={form.company} onChange={(e) => setForm({ ...form, company: e.target.value })} /></div>
          <div className="field"><label>E-Mail</label><input type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} /></div>
          <div className="field"><label>Telefon</label><input value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} /></div>
          <div className="field"><label>Vertrag bis</label><input type="date" value={form.contract_until} onChange={(e) => setForm({ ...form, contract_until: e.target.value })} /></div>
          <div className="field"><label>SLA Reaktion (Stunden)</label><input type="number" min={1} value={form.sla_response_hours} onChange={(e) => setForm({ ...form, sla_response_hours: e.target.value })} /></div>
          <div className="field"><label>Notizen (Hosting, Zugänge…)</label><textarea rows={4} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} /></div>
          <button className="btn" type="submit">Speichern</button>
        </form>
      </Drawer>
    </Shell>
  );
}
