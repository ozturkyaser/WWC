#!/usr/bin/env node
/**
 * WWC MCP (stdio). Cursor/Claude können dieselben Werkzeuge nutzen wie der Portal-KI-Editor.
 *
 * Umgebung:
 *   WWC_API_URL   https://wwc.kiservicehub.de
 *   WWC_API_TOKEN Sanctum-Token aus dem Portal
 *   WWC_SITE_ID   UUID der Site (optional, sonst pro Tool übergeben)
 */
const API = (process.env.WWC_API_URL || "https://wwc.kiservicehub.de").replace(/\/$/, "");
const TOKEN = process.env.WWC_API_TOKEN || "";
const DEFAULT_SITE = process.env.WWC_SITE_ID || "";

const TOOLS = [
  { name: "wwc_site_scan", description: "Scannt Theme, Plugins, Editoren und Seiten in der isolierten Dev-Umgebung.", inputSchema: { type: "object", properties: { site_id: { type: "string" } } } },
  { name: "wwc_content_plan", description: "Erzeugt einen Änderungsplan aus einer Anweisung.", inputSchema: { type: "object", required: ["prompt"], properties: { site_id: { type: "string" }, prompt: { type: "string" } } } },
  { name: "wwc_apply_dev", description: "Wendet den Plan nur in der isolierten Dev-Umgebung an.", inputSchema: { type: "object", properties: { site_id: { type: "string" } } } },
  { name: "wwc_promote_live", description: "Übernimmt den in Dev geprüften Plan auf Live.", inputSchema: { type: "object", properties: { site_id: { type: "string" } } } },
];

async function callApi(path, body) {
  const res = await fetch(`${API}/api${path}`, {
    method: body ? "POST" : "GET",
    headers: {
      Accept: "application/json",
      Authorization: `Bearer ${TOKEN}`,
      ...(body ? { "Content-Type": "application/json" } : {}),
    },
    body: body ? JSON.stringify(body) : undefined,
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    throw new Error(data.message || `HTTP ${res.status}`);
  }
  return data;
}

async function handleTool(name, args = {}) {
  const siteId = args.site_id || DEFAULT_SITE;
  if (!siteId) {
    throw new Error("site_id fehlt (Argument oder WWC_SITE_ID).");
  }
  if (name === "wwc_content_plan") {
    return callApi("/mcp/call", { tool: name, site_id: siteId, arguments: { prompt: args.prompt } });
  }
  return callApi("/mcp/call", { tool: name, site_id: siteId, arguments: args });
}

function reply(id, result, error) {
  const msg = error
    ? { jsonrpc: "2.0", id, error: { code: -32000, message: String(error) } }
    : { jsonrpc: "2.0", id, result };
  process.stdout.write(JSON.stringify(msg) + "\n");
}

let buf = "";
process.stdin.setEncoding("utf8");
process.stdin.on("data", async (chunk) => {
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
    const { id, method, params } = req;
    try {
      if (method === "initialize") {
        reply(id, {
          protocolVersion: "2024-11-05",
          capabilities: { tools: {} },
          serverInfo: { name: "wwc", version: "0.6.21" },
        });
      } else if (method === "notifications/initialized") {
        // ignore
      } else if (method === "tools/list") {
        reply(id, { tools: TOOLS });
      } else if (method === "tools/call") {
        const out = await handleTool(params?.name, params?.arguments || {});
        reply(id, { content: [{ type: "text", text: JSON.stringify(out, null, 2) }] });
      } else {
        reply(id, null, `Unknown method ${method}`);
      }
    } catch (e) {
      reply(id, null, e.message || e);
    }
  }
});
