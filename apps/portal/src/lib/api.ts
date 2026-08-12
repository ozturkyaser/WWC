const API_URL = process.env.NEXT_PUBLIC_API_URL || "http://localhost:8080";

export type ApiError = { message: string; errors?: Record<string, string[]> };

function getToken(): string | null {
  if (typeof window === "undefined") return null;
  return localStorage.getItem("wwc_token");
}

export function setToken(token: string | null) {
  if (typeof window === "undefined") return;
  if (token) localStorage.setItem("wwc_token", token);
  else localStorage.removeItem("wwc_token");
}

export async function api<T>(
  path: string,
  options: RequestInit & { auth?: boolean } = {}
): Promise<T> {
  const { auth = true, headers, ...rest } = options;
  const h = new Headers(headers);
  h.set("Accept", "application/json");
  if (rest.body && !(rest.body instanceof FormData)) {
    h.set("Content-Type", "application/json");
  }
  if (auth) {
    const token = getToken();
    if (token) h.set("Authorization", `Bearer ${token}`);
  }

  const res = await fetch(`${API_URL}/api${path}`, { ...rest, headers: h });
  if (res.status === 204) return undefined as T;

  const contentType = res.headers.get("content-type") || "";
  const data = contentType.includes("application/json")
    ? await res.json()
    : await res.text();

  if (!res.ok) {
    const message =
      typeof data === "object" && data && "message" in data
        ? String((data as ApiError).message)
        : `HTTP ${res.status}`;
    throw new Error(message);
  }

  return data as T;
}

export async function downloadPlugin(filename = "wwc-agent.zip"): Promise<void> {
  const token = getToken();
  const res = await fetch(`${API_URL}/api/plugin/download`, {
    headers: {
      Authorization: `Bearer ${token || ""}`,
      Accept: "application/zip",
    },
  });
  if (!res.ok) {
    throw new Error("Plugin-Download fehlgeschlagen");
  }
  const blob = await res.blob();
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}

export async function downloadSiteBackup(
  siteId: string,
  backupId: string = "latest",
  filename?: string
): Promise<void> {
  const token = getToken();
  const path =
    backupId === "latest" || backupId === "latest-full"
      ? `/api/sites/${siteId}/backups/latest/download`
      : `/api/sites/${siteId}/backups/${encodeURIComponent(backupId)}/download`;
  const res = await fetch(`${API_URL}${path}`, {
    headers: {
      Authorization: `Bearer ${token || ""}`,
      Accept: "application/zip",
    },
  });
  if (!res.ok) {
    let message = "Backup-Download fehlgeschlagen";
    try {
      const data = await res.json();
      if (data?.message) message = String(data.message);
    } catch {
      /* ignore */
    }
    throw new Error(message);
  }
  const blob = await res.blob();
  const headerName = res.headers.get("Content-Disposition")?.match(/filename="?([^";]+)"?/i)?.[1];
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = filename || headerName || `${backupId}.zip`;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}

export { API_URL };
