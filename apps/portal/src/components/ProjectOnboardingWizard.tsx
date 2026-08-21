"use client";

import { FormEvent, useEffect, useState } from "react";
import { api } from "@/lib/api";
import { InstallWizard, InstallInfo } from "@/components/InstallWizard";
import { ProcessBar } from "@/components/ProcessBar";

type Tier = {
  key: string;
  label: string;
  description: string;
  monthly_cents: number | null;
  monthly_eur: number | null;
  hours_included?: number;
  scope: Record<string, boolean | number>;
};

type HourPackage = {
  id: string;
  name: string;
  hours: number;
  price_cents: number;
  billing: string;
  description?: string;
};

type ClientDraft = {
  name: string;
  email: string;
  company: string;
  address: string;
  vat_id: string;
};

type OnboardingState = {
  status?: string | null;
  meta?: {
    steps?: { pair?: string; backup?: string; staging?: string };
    error?: string;
  } | null;
  staging_portal?: {
    exists?: boolean;
    portal_url?: string | null;
    admin_login_url?: string | null;
  } | null;
  progress?: {
    percent: number;
    label?: string;
  } | null;
};

const emptyClient = (): ClientDraft => ({
  name: "",
  email: "",
  company: "",
  address: "",
  vat_id: "",
});

export function ProjectOnboardingWizard({
  onDone,
  embedded = false,
}: {
  onDone?: () => void;
  embedded?: boolean;
}) {
  const [step, setStep] = useState(1);
  const [tiers, setTiers] = useState<Tier[]>([]);
  const [hourPackages, setHourPackages] = useState<HourPackage[]>([]);
  const [hourPackageId, setHourPackageId] = useState("");
  const [siteUrl, setSiteUrl] = useState("https://");
  const [projectName, setProjectName] = useState("");
  const [client, setClient] = useState<ClientDraft>(emptyClient());
  const [impressumUrl, setImpressumUrl] = useState<string | null>(null);
  const [impressumSource, setImpressumSource] = useState("");
  const [tier, setTier] = useState("2");
  const [customEur, setCustomEur] = useState("199");
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState("");
  const [install, setInstall] = useState<InstallInfo | null>(null);
  const [siteId, setSiteId] = useState<string | null>(null);
  const [onboarding, setOnboarding] = useState<OnboardingState | null>(null);

  useEffect(() => {
    api<{ data: Tier[]; hour_packages: HourPackage[] }>("/onboarding/tiers")
      .then((r) => {
        setTiers(r.data);
        setHourPackages(r.hour_packages || []);
      })
      .catch((e) => setMsg(e.message));
  }, []);

  useEffect(() => {
    if (!siteId || step < 5) return;
    const t = setInterval(() => {
      api<{ data: { onboarding: OnboardingState } }>(`/onboarding/sites/${siteId}`)
        .then((r) => {
          setOnboarding(r.data.onboarding);
          if (r.data.onboarding.status === "done") onDone?.();
        })
        .catch(() => undefined);
    }, 4000);
    return () => clearInterval(t);
  }, [siteId, step, onDone]);

  async function runImpressum(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setMsg("");
    try {
      const res = await api<{
        ok: boolean;
        impressum_url?: string;
        client: Partial<ClientDraft>;
        source?: string;
        error?: string;
      }>("/onboarding/impressum", {
        method: "POST",
        body: JSON.stringify({ site_url: siteUrl }),
      });
      setClient({
        name: res.client.name || "",
        email: res.client.email || "",
        company: res.client.company || "",
        address: res.client.address || "",
        vat_id: res.client.vat_id || "",
      });
      setImpressumUrl(res.impressum_url || null);
      setImpressumSource(res.source || "heuristic");
      if (!projectName) {
        setProjectName(res.client.company || res.client.name || "");
      }
      setStep(2);
      setMsg(res.ok ? "Impressum ausgewertet – bitte prüfen und ergänzen." : (res.error || "Teilweise erkannt"));
    } catch (err) {
      setMsg(err instanceof Error ? err.message : "Impressum-Check fehlgeschlagen");
    } finally {
      setBusy(false);
    }
  }

  async function createAll(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setMsg("");
    try {
      const res = await api<{
        install: InstallInfo;
        data: { id: string; sites?: Array<{ id: string }> };
        onboarding: OnboardingState;
      }>("/onboarding/projects", {
        method: "POST",
        body: JSON.stringify({
          site_url: siteUrl,
          project_name: projectName || undefined,
          maintenance_tier: tier,
          monthly_budget_cents: tier === "custom" ? Math.round(parseFloat(customEur) * 100) : undefined,
          hour_package_id: hourPackageId || undefined,
          client,
        }),
      });
      setInstall(res.install);
      setSiteId(res.install.site_id || res.data.sites?.[0]?.id || null);
      setOnboarding(res.onboarding);
      setStep(5);
      setMsg("Projekt angelegt – Plugin verbinden. Danach starten Backup & Dev-Umgebung automatisch.");
      onDone?.();
    } catch (err) {
      setMsg(err instanceof Error ? err.message : "Anlegen fehlgeschlagen");
    } finally {
      setBusy(false);
    }
  }

  const selected = tiers.find((t) => t.key === tier);

  return (
    <div className={embedded ? "wizard-body" : "panel"}>
      {!embedded && (
        <>
          <h2 style={{ marginTop: 0, fontSize: "1.25rem", color: "var(--accent-2)" }}>
            Projekt-Wizard
          </h2>
          <p className="muted" style={{ marginTop: 0 }}>
            Impressum → Kunde → Wartungsstufe → Plugin → Backup & Dev
          </p>
        </>
      )}

      <div className="wizard-steps">
        {["Site", "Kunde", "Stufe", "Prüfen", "Live"].map((label, i) => (
          <span key={label} className={`wizard-step ${step === i + 1 ? "active" : step > i + 1 ? "done" : ""}`}>
            {i + 1}. {label}
          </span>
        ))}
      </div>

      {msg && <div className="flash">{msg}</div>}

      {step === 1 && (
        <form onSubmit={runImpressum}>
          <div className="field">
            <label>WordPress Site-URL</label>
            <input value={siteUrl} onChange={(e) => setSiteUrl(e.target.value)} required placeholder="https://kunde.de" />
          </div>
          <div className="field">
            <label>Projektname (optional)</label>
            <input value={projectName} onChange={(e) => setProjectName(e.target.value)} placeholder="wird aus Impressum vorgeschlagen" />
          </div>
          <button className="btn" disabled={busy} type="submit">
            {busy ? "Prüfe Impressum…" : "Impressum prüfen & weiter"}
          </button>
        </form>
      )}

      {step === 2 && (
        <form onSubmit={(e) => { e.preventDefault(); setStep(3); }}>
          {impressumUrl && (
            <p className="muted" style={{ fontSize: "0.85rem" }}>
              Quelle: <a href={impressumUrl} target="_blank" rel="noreferrer" style={{ color: "var(--accent-2)" }}>{impressumUrl}</a>
              {impressumSource ? ` · ${impressumSource}` : ""}
            </p>
          )}
          <div className="field">
            <label>Kundenname *</label>
            <input value={client.name} onChange={(e) => setClient({ ...client, name: e.target.value })} required />
          </div>
          <div className="field">
            <label>Firma</label>
            <input value={client.company} onChange={(e) => setClient({ ...client, company: e.target.value })} />
          </div>
          <div className="field">
            <label>E-Mail (für Monatsrechnung)</label>
            <input type="email" value={client.email} onChange={(e) => setClient({ ...client, email: e.target.value })} />
          </div>
          <div className="field">
            <label>Adresse</label>
            <textarea rows={3} value={client.address} onChange={(e) => setClient({ ...client, address: e.target.value })} />
          </div>
          <div className="field">
            <label>USt-IdNr.</label>
            <input value={client.vat_id} onChange={(e) => setClient({ ...client, vat_id: e.target.value })} />
          </div>
          <div className="row">
            <button className="btn secondary" type="button" onClick={() => setStep(1)}>Zurück</button>
            <button className="btn" type="submit">Weiter zur Wartungsstufe</button>
          </div>
        </form>
      )}

      {step === 3 && (
        <form onSubmit={(e) => { e.preventDefault(); setStep(4); }}>
          <div className="tier-grid">
            {tiers.map((t) => (
              <label key={t.key} className={`tier-card ${tier === t.key ? "selected" : ""}`}>
                <input
                  type="radio"
                  name="tier"
                  value={t.key}
                  checked={tier === t.key}
                  onChange={() => setTier(t.key)}
                />
                <strong>{t.label}</strong>
                <span className="muted" style={{ display: "block", fontSize: "0.85rem", marginTop: 4 }}>
                  {t.description}
                </span>
                <span style={{ display: "block", marginTop: 8, color: "var(--accent-2)" }}>
                  {t.key === "custom"
                    ? "Individuell"
                    : `${((t.monthly_cents || 0) / 100).toFixed(0)} € / Monat`}
                </span>
              </label>
            ))}
          </div>
          {tier === "custom" && (
            <div className="field" style={{ marginTop: 12 }}>
              <label>Custom Monatspreis (EUR)</label>
              <input value={customEur} onChange={(e) => setCustomEur(e.target.value)} required />
            </div>
          )}
          {selected && (
            <p className="muted" style={{ fontSize: "0.85rem" }}>
              Enthalten in Stufe: {selected.hours_included ?? selected.scope.hours_included ?? 0} Std./Monat
              {selected.key !== "custom" && (
                <> · Auto-Fix {selected.scope.auto_apply_safe_updates ? "an" : "aus"}</>
              )}
            </p>
          )}
          {hourPackages.length > 0 && (
            <div className="field" style={{ marginTop: 12 }}>
              <label>Zusätzliches Stundenpaket (optional)</label>
              <select value={hourPackageId} onChange={(e) => setHourPackageId(e.target.value)}>
                <option value="">Kein Extra-Paket</option>
                {hourPackages.map((p) => (
                  <option key={p.id} value={p.id}>
                    {p.name} · {p.hours} Std. · {(p.price_cents / 100).toFixed(0)} €
                    {p.billing === "monthly" ? "/Mo" : ""}
                  </option>
                ))}
              </select>
            </div>
          )}
          <div className="row">
            <button className="btn secondary" type="button" onClick={() => setStep(2)}>Zurück</button>
            <button className="btn" type="submit">Weiter</button>
          </div>
        </form>
      )}

      {step === 4 && (
        <form onSubmit={createAll}>
          <ul className="muted" style={{ lineHeight: 1.7 }}>
            <li>Site: <strong style={{ color: "var(--text)" }}>{siteUrl}</strong></li>
            <li>Kunde: <strong style={{ color: "var(--text)" }}>{client.name}</strong>{client.email ? ` · ${client.email}` : ""}</li>
            <li>
              Stufe: <strong style={{ color: "var(--text)" }}>{selected?.label || tier}</strong>
              {" · "}
              {tier === "custom"
                ? `${customEur} €`
                : `${((selected?.monthly_cents || 0) / 100).toFixed(0)} €`} / Monat
            </li>
            <li>Nach Pairing: Full-Backup → Development-Umgebung automatisch</li>
            <li>Monatsende: Rechnung per E-Mail an Kunden</li>
          </ul>
          <div className="row">
            <button className="btn secondary" type="button" onClick={() => setStep(3)}>Zurück</button>
            <button className="btn" disabled={busy} type="submit">
              {busy ? "Lege an…" : "Projekt anlegen & Plugin"}
            </button>
          </div>
        </form>
      )}

      {step === 5 && (
        <div>
          {install && <InstallWizard install={install} />}
          <div className="surface surface-pad" style={{ marginTop: 12 }}>
            <h3 style={{ marginTop: 0, fontSize: "1.05rem" }}>Arbeitsprozess</h3>
            <ProcessBar
              progress={{
                percent: onboarding?.progress?.percent ?? (onboarding?.status === "done" ? 100 : 8),
                title: "Onboarding",
                label: onboarding?.progress?.label || "Warte auf Verbindung…",
                status: onboarding?.status === "failed" ? "failed" : onboarding?.status === "done" ? "completed" : "running",
              }}
            />
            <ol className="muted" style={{ lineHeight: 1.8, marginTop: 14 }}>
              <li>Pairing: {labelStep(onboarding?.meta?.steps?.pair, onboarding?.status === "awaiting_pair")}</li>
              <li>Full-Backup: {labelStep(onboarding?.meta?.steps?.backup, onboarding?.status === "awaiting_backup")}</li>
              <li>Development: {labelStep(onboarding?.meta?.steps?.staging, onboarding?.status === "awaiting_staging")}</li>
            </ol>
            {onboarding?.status === "done" && (
              <p style={{ color: "var(--ok)" }}>
                Fertig.
                {onboarding.staging_portal?.portal_url && (
                  <>
                    {" "}
                    <a href={onboarding.staging_portal.portal_url} target="_blank" rel="noreferrer" style={{ color: "var(--accent-2)" }}>
                      Dev-Portal öffnen
                    </a>
                  </>
                )}
              </p>
            )}
            {onboarding?.status === "failed" && (
              <p className="error">{onboarding.meta?.error || "Onboarding fehlgeschlagen"}</p>
            )}
          </div>
        </div>
      )}
    </div>
  );
}

function labelStep(value?: string, running?: boolean) {
  if (value === "done") return "✓ erledigt";
  if (running) return "läuft…";
  return "ausstehend";
}
