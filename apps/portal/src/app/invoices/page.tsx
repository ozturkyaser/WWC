"use client";

import { FormEvent, useEffect, useState } from "react";
import { Shell } from "@/components/Shell";
import { Empty, Flash, PageHeader } from "@/components/ui";
import { api, API_URL } from "@/lib/api";

type Project = { id: string; name: string };
type Invoice = {
  id: string;
  number: string;
  status: string;
  total_cents: number;
  currency: string;
  issued_at: string;
  client?: { name: string };
  project?: { name: string };
};

export default function InvoicesPage() {
  const [invoices, setInvoices] = useState<Invoice[]>([]);
  const [projects, setProjects] = useState<Project[]>([]);
  const [projectId, setProjectId] = useState("");
  const [msg, setMsg] = useState("");
  const [tone, setTone] = useState<"info" | "ok" | "error">("info");

  async function load() {
    const [i, p] = await Promise.all([
      api<{ data: Invoice[] }>("/invoices"),
      api<{ data: Project[] }>("/projects"),
    ]);
    setInvoices(i.data);
    setProjects(p.data);
    if (!projectId && p.data[0]) setProjectId(p.data[0].id);
  }

  useEffect(() => {
    load().catch((e) => {
      setTone("error");
      setMsg(e.message);
    });
  }, []);

  async function generate(e: FormEvent) {
    e.preventDefault();
    await api("/invoices/generate", {
      method: "POST",
      body: JSON.stringify({ project_id: projectId }),
    });
    setTone("ok");
    setMsg("Entwurf erzeugt – bitte prüfen und dann versenden.");
    await load();
  }

  async function markPaid(id: string) {
    await api(`/invoices/${id}/paid`, { method: "POST" });
    await load();
  }

  async function openPdf(id: string) {
    const token = localStorage.getItem("wwc_token");
    const res = await fetch(`${API_URL}/api/invoices/${id}/pdf`, {
      headers: { Authorization: `Bearer ${token || ""}` },
    });
    if (!res.ok) {
      setTone("error");
      setMsg("PDF konnte nicht geladen werden");
      return;
    }
    const blob = await res.blob();
    const url = URL.createObjectURL(blob);
    window.open(url, "_blank");
  }

  return (
    <Shell>
      <PageHeader
        title="Rechnungen"
        subtitle="Monatsabrechnung nach Wartungsstufe – PDF und E-Mail an den Kunden."
      />
      <Flash tone={tone}>{msg}</Flash>

      <form className="toolbar" onSubmit={generate}>
        <select value={projectId} onChange={(e) => setProjectId(e.target.value)}>
          {projects.map((p) => (
            <option key={p.id} value={p.id}>{p.name}</option>
          ))}
        </select>
        <button className="btn" type="submit">Monatsrechnung erzeugen</button>
        <button
          className="btn secondary"
          type="button"
          onClick={() => {
            const token = localStorage.getItem("wwc_token");
            fetch(`${API_URL}/api/invoices/export.csv`, {
              headers: { Authorization: `Bearer ${token}` },
            })
              .then((r) => r.text())
              .then((t) => {
                const blob = new Blob([t], { type: "text/csv" });
                const url = URL.createObjectURL(blob);
                const a = document.createElement("a");
                a.href = url;
                a.download = "invoices.csv";
                a.click();
              });
          }}
        >
          CSV Export
        </button>
      </form>

      <div className="surface">
        {invoices.length === 0 ? (
          <Empty title="Noch keine Rechnungen" text="Erzeuge manuell eine Monatsrechnung oder warte auf den Monatswechsel." />
        ) : (
          <table className="table">
            <thead>
              <tr>
                <th>Nummer</th>
                <th>Kunde / Projekt</th>
                <th>Status</th>
                <th>Betrag</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {invoices.map((inv) => (
                <tr key={inv.id}>
                  <td>
                    <div className="cell-title">{inv.number}</div>
                    <div className="cell-sub">{new Date(inv.issued_at).toLocaleDateString("de-DE")}</div>
                  </td>
                  <td>
                    <div>{inv.client?.name}</div>
                    <div className="cell-sub">{inv.project?.name}</div>
                  </td>
                  <td><span className={`badge ${inv.status}`}>{inv.status}</span></td>
                  <td>{(inv.total_cents / 100).toFixed(2)} {inv.currency}</td>
                  <td>
                    <div className="action-menu">
                      <button className="btn secondary sm" type="button" onClick={() => openPdf(inv.id)}>PDF</button>
                      {inv.status === "draft" && (
                        <button className="btn sm" type="button" onClick={async () => {
                          await api(`/invoices/${inv.id}/send`, { method: "POST" });
                          setTone("ok");
                          setMsg("Rechnung versendet");
                          await load();
                        }}>Senden</button>
                      )}
                      {inv.status !== "paid" && (
                        <button className="btn secondary sm" type="button" onClick={() => markPaid(inv.id)}>Bezahlt</button>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </Shell>
  );
}
