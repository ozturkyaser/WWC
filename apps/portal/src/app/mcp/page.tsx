"use client";

import { Shell } from "@/components/Shell";
import { McpConnect } from "@/components/McpConnect";
import { PageHeader } from "@/components/ui";

export default function McpPage() {
  return (
    <Shell>
      <PageHeader
        title="MCP"
        subtitle="Verbindungscode für Cursor. Damit steuerst du Live-Sites und die isolierte Kopie über das Portal – nicht über ein Menü in WordPress."
      />
      <McpConnect />
    </Shell>
  );
}
