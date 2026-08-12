"use client";

import { FormEvent, useEffect, useState } from "react";
import { Shell } from "@/components/Shell";
import { Flash, PageHeader, Section } from "@/components/ui";
import { api } from "@/lib/api";

type HourPackage = {
  id: string;
  name: string;
  hours: number;
  price_cents: number;
  billing: "once" | "monthly";
  active: boolean;
  description?: string;
};

type Tier = {
  key: string;
  label: string;
  description?: string;
  monthly_cents: number | null;
  hours_included?: number;
};

type Org = {
  id: string;
  name: string;
  billing_day: number;
  billing_profile?: {
    company?: string;
    address?: string;
    vat_id?: string;
    tax_rate?: number;
    small_business?: boolean;
  };
  patchstack_api_key?: string;
};

function newPackage(): HourPackage {
  return {
    id: `hp-${Date.now().toString(36)}`,
    name: "",
    hours: 5,
    price_cents: 37500,
    billing: "once",
    active: true,
    description: "",
  };
}

export default function SettingsPage() {
  const [org, setOrg] = useState<Org | null>(null);
  const [packages, setPackages] = useState<HourPackage[]>([]);
  const [tiers, setTiers] = useState<Tier[]>([]);
  const [msg, setMsg] = useState("");
  const [tone, setTone] = useState<"info" | "ok" | "error">("info");

  useEffect(() => {
    api<{ data: Org; catalog: { hour_packages: HourPackage[]; maintenance_tiers: Tier[] } }>("/organization")
      .then((r) => {
        setOrg(r.data);
        setPackages(r.catalog.hour_packages);
        setTiers(r.catalog.maintenance_tiers);
      })
      .catch((e) => {
        setTone("error");
        setMsg(e.message);
      });
  }, []);

  async function save(e: FormEvent) {
    e.preventDefault();
    if (!org) return;
    try {
      const res = await api<{
        data: Org;
        catalog: { hour_packages: HourPackage[]; maintenance_tiers: Tier[] };
      }>("/organization", {
        method: "PUT",
        body: JSON.stringify({
          name: org.name,
          billing_day: org.billing_day,
          billing_profile: org.billing_profile,
          patchstack_api_key: org.patchstack_api_key,
          hour_packages: packages,
          maintenance_tiers: tiers.map((t) => ({
            key: t.key,
            label: t.label,
            description: t.description,
            monthly_cents: t.monthly_cents,
            hours_included: t.hours_included,
          })),
        }),
      });
      setOrg(res.data);
      setPackages(res.catalog.hour_packages);
      setTiers(res.catalog.maintenance_tiers);
      setTone("ok");
      setMsg("Gespeichert");
    } catch (err) {
      setTone("error");
      setMsg(err instanceof Error ? err.message : "Speichern fehlgeschlagen");
    }
  }

  if (!org) return <Shell><p className="muted">Lade…</p></Shell>;

  const profile = org.billing_profile || {};

  return (
    <Shell>
      <PageHeader
        title="Einstellungen"
        subtitle="Organisation, Stundenpakete, Wartungsstufen und Rechnungsdaten."
      />
      <Flash tone={tone}>{msg}</Flash>

      <form onSubmit={save}>
        <Section title="Organisation" note="Name im Portal">
          <div className="field" style={{ marginBottom: 0 }}>
            <label>Organisationsname</label>
            <input value={org.name} onChange={(e) => setOrg({ ...org, name: e.target.value })} />
          </div>
        </Section>

        <Section
          title="Stundenpakete"
          note="Verkaufbare Support-Kontingente – Name, Stunden und Preis."
          action={
            <button
              className="btn secondary sm"
              type="button"
              onClick={() => setPackages((prev) => [...prev, newPackage()])}
            >
              Paket hinzufügen
            </button>
          }
        >
          {packages.length === 0 && <p className="muted" style={{ margin: 0 }}>Noch keine Pakete.</p>}
          <div style={{ display: "flex", flexDirection: "column", gap: 14 }}>
            {packages.map((pkg, idx) => (
              <div key={pkg.id} className="surface surface-pad" style={{ background: "rgba(10,14,18,0.35)" }}>
                <div className="grid form-2">
                  <div className="field">
                    <label>Name</label>
                    <input
                      value={pkg.name}
                      onChange={(e) => {
                        const next = [...packages];
                        next[idx] = { ...pkg, name: e.target.value };
                        setPackages(next);
                      }}
                      required
                      placeholder="z. B. 10 Stunden"
                    />
                  </div>
                  <div className="field">
                    <label>Abrechnung</label>
                    <select
                      value={pkg.billing}
                      onChange={(e) => {
                        const next = [...packages];
                        next[idx] = { ...pkg, billing: e.target.value as "once" | "monthly" };
                        setPackages(next);
                      }}
                    >
                      <option value="once">Einmalig</option>
                      <option value="monthly">Monatlich</option>
                    </select>
                  </div>
                  <div className="field">
                    <label>Stunden</label>
                    <input
                      type="number"
                      min={0}
                      step={0.5}
                      value={pkg.hours}
                      onChange={(e) => {
                        const next = [...packages];
                        next[idx] = { ...pkg, hours: Number(e.target.value) };
                        setPackages(next);
                      }}
                      required
                    />
                  </div>
                  <div className="field">
                    <label>Preis (EUR)</label>
                    <input
                      type="number"
                      min={0}
                      step={0.01}
                      value={(pkg.price_cents / 100).toFixed(2)}
                      onChange={(e) => {
                        const next = [...packages];
                        next[idx] = { ...pkg, price_cents: Math.round(parseFloat(e.target.value || "0") * 100) };
                        setPackages(next);
                      }}
                      required
                    />
                  </div>
                </div>
                <div className="field">
                  <label>Beschreibung (optional)</label>
                  <input
                    value={pkg.description || ""}
                    onChange={(e) => {
                      const next = [...packages];
                      next[idx] = { ...pkg, description: e.target.value };
                      setPackages(next);
                    }}
                  />
                </div>
                <div className="row" style={{ justifyContent: "space-between" }}>
                  <label className="check-row">
                    <input
                      type="checkbox"
                      checked={pkg.active}
                      onChange={(e) => {
                        const next = [...packages];
                        next[idx] = { ...pkg, active: e.target.checked };
                        setPackages(next);
                      }}
                    />
                    Aktiv
                  </label>
                  <button
                    className="btn danger sm"
                    type="button"
                    onClick={() => setPackages(packages.filter((_, i) => i !== idx))}
                  >
                    Entfernen
                  </button>
                </div>
              </div>
            ))}
          </div>
        </Section>

        <Section title="Wartungsstufen" note="Monatspreis und enthaltene Stunden je Stufe">
          <div style={{ display: "flex", flexDirection: "column", gap: 12 }}>
            {tiers.map((tier, idx) => (
              <div key={tier.key} className="grid form-2" style={{ alignItems: "end" }}>
                <div className="field">
                  <label>{tier.label} – Monatspreis (EUR){tier.key === "custom" ? " (optional)" : ""}</label>
                  <input
                    type="number"
                    min={0}
                    step={1}
                    disabled={tier.key === "custom"}
                    value={tier.monthly_cents === null ? "" : (tier.monthly_cents / 100).toFixed(0)}
                    onChange={(e) => {
                      const next = [...tiers];
                      next[idx] = {
                        ...tier,
                        monthly_cents: e.target.value === "" ? null : Math.round(Number(e.target.value) * 100),
                      };
                      setTiers(next);
                    }}
                    placeholder={tier.key === "custom" ? "individuell im Wizard" : ""}
                  />
                </div>
                <div className="field">
                  <label>Enthaltene Stunden / Monat</label>
                  <input
                    type="number"
                    min={0}
                    step={0.5}
                    value={tier.hours_included ?? 0}
                    onChange={(e) => {
                      const next = [...tiers];
                      next[idx] = { ...tier, hours_included: Number(e.target.value) };
                      setTiers(next);
                    }}
                  />
                </div>
              </div>
            ))}
          </div>
        </Section>

        <Section title="Rechnungssteller" note="Erscheint auf PDF-Rechnungen">
          <div className="grid form-2">
            <div className="field">
              <label>Firmenname</label>
              <input
                value={profile.company || ""}
                onChange={(e) => setOrg({ ...org, billing_profile: { ...profile, company: e.target.value } })}
              />
            </div>
            <div className="field">
              <label>USt-IdNr.</label>
              <input
                value={profile.vat_id || ""}
                onChange={(e) => setOrg({ ...org, billing_profile: { ...profile, vat_id: e.target.value } })}
              />
            </div>
          </div>
          <div className="field">
            <label>Adresse</label>
            <textarea
              rows={3}
              value={profile.address || ""}
              onChange={(e) => setOrg({ ...org, billing_profile: { ...profile, address: e.target.value } })}
            />
          </div>
          <div className="grid form-2">
            <div className="field">
              <label>USt-Satz (%)</label>
              <input
                type="number"
                value={profile.tax_rate ?? 19}
                onChange={(e) => setOrg({ ...org, billing_profile: { ...profile, tax_rate: Number(e.target.value) } })}
              />
            </div>
            <div className="field">
              <label>Billing-Tag (Monat)</label>
              <input
                type="number"
                min={1}
                max={28}
                value={org.billing_day}
                onChange={(e) => setOrg({ ...org, billing_day: Number(e.target.value) })}
              />
            </div>
          </div>
          <label className="check-row">
            <input
              type="checkbox"
              checked={Boolean(profile.small_business)}
              onChange={(e) => setOrg({ ...org, billing_profile: { ...profile, small_business: e.target.checked } })}
            />
            Kleinunternehmer §19 UStG
          </label>
        </Section>

        <Section title="Integrationen" note="Optional">
          <div className="field" style={{ marginBottom: 0 }}>
            <label>Patchstack API Key</label>
            <input
              value={org.patchstack_api_key || ""}
              onChange={(e) => setOrg({ ...org, patchstack_api_key: e.target.value })}
              placeholder="optional"
            />
          </div>
        </Section>

        <button className="btn" type="submit">Alles speichern</button>
      </form>
    </Shell>
  );
}
