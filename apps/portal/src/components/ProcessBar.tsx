"use client";

import { useState } from "react";
import { api } from "@/lib/api";

export type ProgressLogEntry = {
  at?: string;
  message: string;
  percent?: number;
};

export type ProgressItemResult = {
  type?: string;
  slug?: string;
  ok?: boolean;
  error?: string | null;
  message?: string | null;
};

export type ProgressUi = {
  percent: number;
  label?: string;
  title?: string;
  status?: string;
  outcome?: "ok" | "error" | string | null;
  error?: string | null;
  items?: ProgressItemResult[];
  log?: ProgressLogEntry[];
};

export function ProcessBar({
  progress,
  compact = false,
  jobId,
  onCancelled,
  showLog = true,
}: {
  progress: ProgressUi;
  compact?: boolean;
  jobId?: string;
  onCancelled?: () => void;
  showLog?: boolean;
}) {
  const [busy, setBusy] = useState(false);
  const pct = Math.max(0, Math.min(100, Math.round(progress.percent || 0)));
  const failed = progress.status === "failed" || progress.outcome === "error";
  const done = !failed && (progress.status === "completed" || progress.outcome === "ok");
  const cancelled = progress.status === "cancelled";
  const active = !done && !failed && !cancelled && !!jobId;
  const log = showLog && !compact ? (progress.log || []).slice(-24) : [];
  const items = progress.items || [];
  const errorText = progress.error || (failed ? progress.label : null);

  async function cancel() {
    if (!jobId || busy) return;
    setBusy(true);
    try {
      await api(`/jobs/${jobId}/cancel`, { method: "POST" });
      onCancelled?.();
    } catch {
      // parent poll will refresh state
    } finally {
      setBusy(false);
    }
  }

  return (
    <div
      className={`process ${compact ? "compact" : ""} ${failed ? "failed" : ""} ${done ? "done" : ""} ${cancelled ? "cancelled" : ""}`}
    >
      <div className="process-top">
        <div className="process-title">
          {progress.title || "Prozess"}
          {done && <span className="badge completed" style={{ marginLeft: 8 }}>OK</span>}
          {failed && <span className="badge failed" style={{ marginLeft: 8 }}>Fehler</span>}
          {!compact && !failed && progress.label && !done && (
            <span className="process-label"> · {progress.label}</span>
          )}
          {!compact && done && progress.label && progress.label !== "OK" && progress.label !== "Fertig" && (
            <span className="process-label"> · {progress.label}</span>
          )}
        </div>
        <div className="process-actions">
          {active && (
            <button
              type="button"
              className="btn danger sm"
              disabled={busy}
              onClick={cancel}
            >
              {busy ? "…" : "Abbrechen"}
            </button>
          )}
          <div className="process-pct">
            {cancelled ? "–" : done ? "OK" : failed ? "!" : `${pct}%`}
          </div>
        </div>
      </div>
      {compact && progress.label && <div className="process-label-line">{progress.label}</div>}
      <div className="process-track" aria-valuemin={0} aria-valuemax={100} aria-valuenow={pct} role="progressbar">
        <div className="process-fill" style={{ width: `${cancelled ? 0 : failed ? Math.max(pct, 8) : pct}%` }} />
      </div>
      {active && (() => {
        const lastAt = log.length ? Date.parse(String(log[log.length - 1]?.at || "")) : 0;
        const stale = lastAt > 0 && Date.now() - lastAt > 3 * 60 * 1000;
        return stale ? (
          <p className="muted" style={{ margin: "8px 0 0", fontSize: "0.85rem" }}>
            Keine neuen Meldungen seit über 3 Minuten. Der Hoster hat den Vorgang vermutlich
            abgebrochen – Agent aktualisieren und Backup erneut starten.
          </p>
        ) : null;
      })()}
      {failed && errorText && (
        <div className="process-error" role="alert">
          {errorText}
        </div>
      )}
      {items.length > 0 && (
        <ul className="process-items">
          {items.map((item, i) => (
            <li key={`${item.type}-${item.slug}-${i}`}>
              <span className={`badge ${item.ok ? "completed" : "failed"}`}>
                {item.ok ? "OK" : "Fehler"}
              </span>
              <span>
                {item.type || "item"}
                {item.slug ? `: ${item.slug}` : ""}
              </span>
              {!item.ok && item.error && <span className="process-item-error">{item.error}</span>}
            </li>
          ))}
        </ul>
      )}
      {log.length > 0 && (
        <ol className="process-log">
          {log.map((entry, i) => (
            <li key={`log-${i}-${entry.percent ?? "x"}-${entry.at || ""}`}>
              <span className="process-log-pct">
                {entry.percent != null ? `${entry.percent}%` : "·"}
              </span>
              <span className="process-log-msg">{entry.message}</span>
              {entry.at && (
                <span className="process-log-time">
                  {new Date(entry.at).toLocaleTimeString("de-DE")}
                </span>
              )}
            </li>
          ))}
        </ol>
      )}
    </div>
  );
}

export function ProcessList({
  items,
  onCancelled,
}: {
  items: Array<ProgressUi & { id?: string; site_name?: string }>;
  onCancelled?: () => void;
}) {
  if (!items.length) return null;
  return (
    <div className="process-list">
      {items.map((item, i) => (
        <ProcessBar
          key={item.id || `${item.title}-${i}`}
          jobId={item.id}
          onCancelled={onCancelled}
          progress={{
            ...item,
            title: item.site_name ? `${item.site_name}: ${item.title || "Job"}` : item.title,
          }}
        />
      ))}
    </div>
  );
}
