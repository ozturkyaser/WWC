"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { api, setToken } from "@/lib/api";

const groups = [
  {
    label: "Betrieb",
    items: [
      { href: "/dashboard", label: "Übersicht" },
      { href: "/sites", label: "Sites" },
      { href: "/security", label: "Security" },
    ],
  },
  {
    label: "Kunden",
    items: [
      { href: "/projects", label: "Projekte" },
      { href: "/invoices", label: "Rechnungen" },
    ],
  },
  {
    label: "System",
    items: [{ href: "/settings", label: "Einstellungen" }],
  },
];

export function Shell({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();
  const router = useRouter();

  async function logout() {
    try {
      await api("/auth/logout", { method: "POST" });
    } catch {
      /* ignore */
    }
    setToken(null);
    router.push("/login");
  }

  return (
    <div className="shell">
      <aside className="sidebar">
        <div className="brand">
          <span className="brand-mark">WWC</span>
          <span className="brand-sub">Wartungsportal</span>
        </div>
        <nav>
          {groups.map((group) => (
            <div className="nav-group" key={group.label}>
              <span className="nav-group-label">{group.label}</span>
              {group.items.map((l) => (
                <Link
                  key={l.href}
                  href={l.href}
                  className={pathname.startsWith(l.href) ? "nav-link active" : "nav-link"}
                >
                  {l.label}
                </Link>
              ))}
            </div>
          ))}
        </nav>
        <button className="ghost" onClick={logout} type="button">
          Abmelden
        </button>
      </aside>
      <main className="content">{children}</main>
    </div>
  );
}
