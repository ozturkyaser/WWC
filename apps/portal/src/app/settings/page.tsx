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
  alert_settings?: { roles?: string[]; quiet_hours?: { from?: string; to?: string; except_critical?: boolean } };
};

function PasswordSection() {
  const [currentPassword, setCurrentPassword] = useState("");
  const [password, setPassword] = useState("");
  const [passwordConfirmation, setPasswordConfirmation] = useState("");
  const [msg, setMsg] = useState("");
  const [tone, setTone] = useState<"info" | "ok" | "error">("info");
  const [saving, setSaving] = useState(false);

  async function save(e: FormEvent) {
    e.preventDefault();
    setMsg("");
    if (password !== passwordConfirmation) {
      setTone("error");
      setMsg("Die neuen Passwörter stimmen nicht überein.");
      return;
    }
    setSaving(true);
    try {
      await api("/auth/password", {
        method: "POST",
        body: JSON.stringify({
          current_password: currentPassword,
          password,
          password_confirmation: passwordConfirmation,
        }),
      });
      setCurrentPassword("");
      setPassword("");
      setPasswordConfirmation("");
      setTone("ok");
      setMsg("Passwort geändert. Andere Sitzungen wurden abgemeldet.");
    } catch (err) {
      setTone("error");
      setMsg(err instanceof Error ? err.message : "Passwort ändern fehlgeschlagen");
    } finally {
      setSaving(false);
    }
  }

  return (
    <Section
      title="Passwort"
      note="Aktuelles Passwort bestätigen, dann ein neues mit mindestens 8 Zeichen setzen."
    >
      <Flash tone={tone}>{msg}</Flash>
      <form onSubmit={save}>
        <div className="field">
          <label htmlFor="current_password">Aktuelles Passwort</label>
          <input
            id="current_password"
            type="password"
            autoComplete="current-password"
            value={currentPassword}
            onChange={(e) => setCurrentPassword(e.target.value)}
            required
          />
        </div>
        <div className="grid form-2">
          <div className="field">
            <label htmlFor="new_password">Neues Passwort</label>
            <input
              id="new_password"
              type="password"
              autoComplete="new-password"
              minLength={8}
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              required
            />
          </div>
          <div className="field">
            <label htmlFor="new_password_confirmation">Neues Passwort wiederholen</label>
            <input
              id="new_password_confirmation"
              type="password"
              autoComplete="new-password"
              minLength={8}
              value={passwordConfirmation}
              onChange={(e) => setPasswordConfirmation(e.target.value)}
              required
            />
          </div>
        </div>
        <button className="btn" type="submit" disabled={saving}>
          {saving ? "Speichere…" : "Passwort ändern"}
        </button>
      </form>
    </Section>
  );
}

function TwoFactorSection() {
  const [enabled, setEnabled] = useState<boolean | null>(null);
  const [setup, setSetup] = useState<{ secret: string; otpauth_uri: string } | null>(null);
  const [code, setCode] = useState("");
  const [recoveryCodes, setRecoveryCodes] = useState<string[]>([]);
  const [disablePassword, setDisablePassword] = useState("");
  const [disableCode, setDisableCode] = useState("");
  const [showDisable, setShowDisable] = useState(false);
  const [msg, setMsg] = useState("");
  const [tone, setTone] = useState<"info" | "ok" | "error">("info");

  useEffect(() => {
    api<{ two_factor_enabled: boolean }>("/auth/me")
      .then((r) => setEnabled(r.two_factor_enabled))
      .catch(() => setEnabled(false));
  }, []);

  async function startSetup() {
    setMsg("");
    try {
      const res = await api<{ secret: string; otpauth_uri: string }>("/auth/2fa/setup", { method: "POST" });
      setSetup(res);
    } catch (e) {
      setTone("error");
      setMsg(e instanceof Error ? e.message : "Setup fehlgeschlagen");
    }
  }

  async function enable(e: FormEvent) {
    e.preventDefault();
    setMsg("");
    try {
      const res = await api<{ enabled: boolean; recovery_codes: string[] }>("/auth/2fa/enable", {
        method: "POST",
        body: JSON.stringify({ code }),
      });
      setRecoveryCodes(res.recovery_codes);
      setEnabled(true);
      setSetup(null);
      setCode("");
      setTone("ok");
      setMsg("2FA aktiviert. Bewahre die Wiederherstellungscodes sicher auf – sie werden nur einmal angezeigt.");
    } catch (err) {
      setTone("error");
      setMsg(err instanceof Error ? err.message : "Aktivierung fehlgeschlagen");
    }
  }

  async function disable(e: FormEvent) {
    e.preventDefault();
    setMsg("");
    try {
      await api("/auth/2fa/disable", {
        method: "POST",
        body: JSON.stringify({ password: disablePassword, code: disableCode }),
      });
      setEnabled(false);
      setShowDisable(false);
      setDisablePassword("");
      setDisableCode("");
      setRecoveryCodes([]);
      setTone("ok");
      setMsg("2FA deaktiviert.");
    } catch (err) {
      setTone("error");
      setMsg(err instanceof Error ? err.message : "Deaktivierung fehlgeschlagen");
    }
  }

  return (
    <Section
      title="Zwei-Faktor-Authentifizierung (2FA)"
      note="Schützt dein Konto zusätzlich mit einem Einmalcode aus einer Authenticator-App."
    >
      <Flash tone={tone}>{msg}</Flash>

      {enabled === null && <p className="muted" style={{ margin: 0 }}>Lade…</p>}

      {enabled === false && !setup && (
        <button className="btn secondary" type="button" onClick={startSetup}>
          2FA einrichten
        </button>
      )}

      {setup && (
        <form onSubmit={enable}>
          <p style={{ marginTop: 0 }}>
            1. Öffne deine Authenticator-App (z.&nbsp;B. Google Authenticator, Authy) und füge das Konto hinzu –
            entweder über den Link oder durch manuelle Eingabe des Geheimschlüssels:
          </p>
          <p>
            <a href={setup.otpauth_uri} style={{ wordBreak: "break-all" }}>In Authenticator-App öffnen</a>
          </p>
          <div className="field">
            <label>Geheimschlüssel (manuelle Eingabe)</label>
            <input readOnly value={setup.secret} onFocus={(e) => e.target.select()} />
          </div>
          <div className="field">
            <label>2. Code aus der App eingeben</label>
            <input
              inputMode="numeric"
              placeholder="6-stelliger Code"
              value={code}
              onChange={(e) => setCode(e.target.value)}
              required
            />
          </div>
          <div className="row">
            <button className="btn" type="submit">Aktivieren</button>
            <button className="btn secondary" type="button" onClick={() => setSetup(null)}>Abbrechen</button>
          </div>
        </form>
      )}

      {recoveryCodes.length > 0 && (
        <div className="surface surface-pad" style={{ marginTop: 12, background: "rgba(10,14,18,0.35)" }}>
          <strong>Wiederherstellungscodes</strong>
          <p className="muted" style={{ fontSize: 12 }}>
            Jeder Code funktioniert genau einmal, falls du keinen Zugriff auf deine App hast.
          </p>
          <pre style={{ margin: 0, fontSize: 13 }}>{recoveryCodes.join("\n")}</pre>
        </div>
      )}

      {enabled === true && recoveryCodes.length === 0 && (
        <div>
          <p style={{ marginTop: 0 }}>2FA ist <strong>aktiv</strong>.</p>
          {!showDisable ? (
            <button className="btn danger sm" type="button" onClick={() => setShowDisable(true)}>
              2FA deaktivieren
            </button>
          ) : (
            <form onSubmit={disable}>
              <div className="grid form-2">
                <div className="field">
                  <label>Passwort</label>
                  <input
                    type="password"
                    value={disablePassword}
                    onChange={(e) => setDisablePassword(e.target.value)}
                    required
                  />
                </div>
                <div className="field">
                  <label>2FA-Code</label>
                  <input
                    inputMode="numeric"
                    value={disableCode}
                    onChange={(e) => setDisableCode(e.target.value)}
                    required
                  />
                </div>
              </div>
              <div className="row">
                <button className="btn danger" type="submit">Deaktivieren</button>
                <button className="btn secondary" type="button" onClick={() => setShowDisable(false)}>Abbrechen</button>
              </div>
            </form>
          )}
        </div>
      )}
    </Section>
  );
}

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
          alert_settings: org.alert_settings,
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

        <Section title="Benachrichtigungen" note="Wer Mails bekommt und wann Ruhe ist">
          <label className="check-row">
            <input
              type="checkbox"
              checked={(org.alert_settings?.roles || ["owner", "admin", "technician"]).includes("technician")}
              onChange={(e) => {
                const roles = new Set(org.alert_settings?.roles || ["owner", "admin", "technician"]);
                if (e.target.checked) roles.add("technician");
                else roles.delete("technician");
                setOrg({ ...org, alert_settings: { ...org.alert_settings, roles: [...roles] } });
              }}
            />
            Techniker benachrichtigen
          </label>
          <div className="grid form-2" style={{ marginTop: 10 }}>
            <div className="field">
              <label>Ruhe von</label>
              <input
                type="time"
                value={org.alert_settings?.quiet_hours?.from || "22:00"}
                onChange={(e) => setOrg({
                  ...org,
                  alert_settings: {
                    ...org.alert_settings,
                    quiet_hours: { ...org.alert_settings?.quiet_hours, from: e.target.value, except_critical: true },
                  },
                })}
              />
            </div>
            <div className="field">
              <label>Ruhe bis</label>
              <input
                type="time"
                value={org.alert_settings?.quiet_hours?.to || "07:00"}
                onChange={(e) => setOrg({
                  ...org,
                  alert_settings: {
                    ...org.alert_settings,
                    quiet_hours: { ...org.alert_settings?.quiet_hours, to: e.target.value, except_critical: true },
                  },
                })}
              />
            </div>
          </div>
          <p className="muted" style={{ fontSize: 12 }}>Kritische Alarme (offline, HTTP down) kommen trotzdem.</p>
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

      <PasswordSection />
      <TwoFactorSection />
    </Shell>
  );
}
