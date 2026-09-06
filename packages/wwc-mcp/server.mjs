#!/usr/bin/env node
/**
 * Cursor/Claude-MCP für WWC.
 * Einmal verbinden, dann alle gekoppelten WordPress-Sites steuern.
 *
 *   WWC_API_URL=https://wwc.kiservicehub.de
 *   WWC_TOKEN=<Sanctum-Token aus dem Portal>
 */
const apiUrl = (process.env.WWC_API_URL || "https://wwc.kiservicehub.de").replace(/\/$/, "");
const token = process.env.WWC_TOKEN || process.env.WWC_API_TOKEN || "";

const tools = [
  {
    name: "wwc_sites",
    description: "Listet die WordPress-Sites der Organisation.",
    inputSchema: { type: "object", properties: {}, additionalProperties: false },
  },
  {
    name: "wwc_wp_tools",
    description: "WordPress-Tools einer Site (info, scan, posts, SEO, Theme).",
    inputSchema: {
      type: "object",
      properties: {
        site_id: { type: "string" },
        target: { type: "string", enum: ["clone", "live"] },
      },
      required: ["site_id"],
    },
  },
  {
    name: "wwc_wp_call",
    description:
      "Ruft ein WordPress-Tool auf. Schreiben auf Live braucht confirm_live=true.",
    inputSchema: {
      type: "object",
      properties: {
        site_id: { type: "string" },
        tool: { type: "string" },
        arguments: { type: "object" },
        target: { type: "string", enum: ["clone", "live"] },
        confirm_live: { type: "boolean" },
      },
      required: ["site_id", "tool"],
    },
  },
  {
    name: "wwc_site_scan",
    description: "Scannt Theme, Plugins, Seiten. target: clone oder live.",
    inputSchema: {
      type: "object",
      properties: {
        site_id: { type: "string" },
        target: { type: "string", enum: ["clone", "live"] },
      },
      required: ["site_id"],
    },
  },
];

async function wwcCall(tool, args) {
  if (!token) {
    throw new Error("WWC_TOKEN fehlt. Portal-Token in die MCP-Umgebung setzen.");
  }
  const body = { tool, arguments: args || {} };
  if (args?.site_id) {
    body.site_id = args.site_id;
  }
  const res = await fetch(`${apiUrl}/api/mcp/call`, {
    method: "POST",
    headers: {
      Authorization: `Bearer ${token}`,
      Accept: "application/json",
      "Content-Type": "application/json",
    },
    body: JSON.stringify(body),
  });
  const json = await res.json();
  if (!res.ok || json.ok === false) {
    throw new Error(json.message || json.error || `HTTP ${res.status}`);
  }
  return json.data ?? json;
}

function reply(id, result, error) {
  const msg = error
    ? { jsonrpc: "2.0", id, error: { code: -32000, message: String(error) } }
    : { jsonrpc: "2.0", id, result };
  process.stdout.write(JSON.stringify(msg) + "\n");
}

let buf = "";
process.stdin.setEncoding("utf8");
process.stdin.on("data", (chunk) => {
  buf += chunk;
  let idx;
  while ((idx = buf.indexOf("\n")) >= 0) {
    const line = buf.slice(0, idx).trim();
    buf = buf.slice(idx + 1);
    if (!line) continue;
    let req;
    try {
      req = JSON.parse(line);
    } catch {
      continue;
    }
    handle(req).catch((err) => reply(req.id ?? null, null, err.message));
  }
});

async function handle(req) {
  const { id, method, params } = req;
  if (method === "initialize") {
    reply(id, {
      protocolVersion: "2024-11-05",
      capabilities: { tools: {} },
      serverInfo: { name: "wwc", version: "0.1.0" },
    });
    return;
  }
  if (method === "notifications/initialized") {
    return;
  }
  if (method === "tools/list") {
    reply(id, { tools });
    return;
  }
  if (method === "tools/call") {
    const name = params?.name;
    const args = params?.arguments || {};
    const data = await wwcCall(name, args);
    reply(id, {
      content: [{ type: "text", text: JSON.stringify(data, null, 2) }],
    });
    return;
  }
  reply(id, null, `Unbekannte Methode: ${method}`);
}
