import { NextRequest, NextResponse } from "next/server";

export const runtime = "nodejs";
export const dynamic = "force-dynamic";
export const maxDuration = 60;

const PORT_MIN = 9100;
const PORT_MAX = 9299;

type Ctx = { params: Promise<{ port: string; path?: string[] }> };

async function proxy(req: NextRequest, ctx: Ctx): Promise<Response> {
  const { port: portRaw, path: segments } = await ctx.params;
  const port = Number(portRaw);
  if (!Number.isInteger(port) || port < PORT_MIN || port > PORT_MAX) {
    return NextResponse.json({ message: "Ungültiger Clone-Port" }, { status: 404 });
  }

  const prefix = `/clone/${port}`;
  const pathname = req.nextUrl.pathname;
  const rest = pathname.startsWith(prefix)
    ? pathname.slice(prefix.length)
    : `/${(segments || []).join("/")}`;
  const base = (process.env.WWC_CLONE_PROXY_TARGET || "http://127.0.0.1").replace(/\/$/, "");
  const target = `${base}:${port}${rest || "/"}${req.nextUrl.search}`;

  const headers = new Headers();
  for (const name of ["cookie", "content-type", "accept", "accept-language", "user-agent"]) {
    const value = req.headers.get(name);
    if (value) headers.set(name, value);
  }
  headers.set("host", req.headers.get("host") || "localhost");
  headers.set("x-forwarded-proto", "https");
  headers.set("x-forwarded-host", req.headers.get("host") || "");
  headers.set("x-forwarded-prefix", prefix);

  const method = req.method.toUpperCase();
  const body = method === "GET" || method === "HEAD" ? undefined : await req.arrayBuffer();

  let upstream: Response;
  try {
    upstream = await fetch(target, {
      method,
      headers,
      body,
      redirect: "manual",
    });
  } catch (error) {
    const message = error instanceof Error ? error.message : "Clone nicht erreichbar";
    return NextResponse.json(
      {
        message: "Isolierte Umgebung antwortet nicht. Docker-Stack prüfen oder LAN-URL nutzen.",
        detail: message,
      },
      { status: 502 }
    );
  }

  const out = new Headers();
  upstream.headers.forEach((value, key) => {
    if (["transfer-encoding", "connection", "keep-alive", "x-frame-options", "content-security-policy"].includes(key.toLowerCase())) {
      return;
    }
    if (key.toLowerCase() === "location") {
      out.append(key, rewriteLocation(value, port, prefix, req));
      return;
    }
    if (key.toLowerCase() === "set-cookie") {
      out.append(key, rewriteCookie(value, prefix));
      return;
    }
    out.append(key, value);
  });

  return new NextResponse(upstream.body, { status: upstream.status, headers: out });
}

function rewriteLocation(location: string, port: number, prefix: string, req: NextRequest): string {
  try {
    const url = new URL(location, `http://127.0.0.1:${port}/`);
    const isLocal =
      url.hostname === "127.0.0.1" ||
      url.hostname === "localhost" ||
      url.port === String(port);
    const isPublic = url.hostname === req.nextUrl.hostname;
    if (!isLocal && !isPublic) {
      return location;
    }
    const path =
      url.pathname === prefix || url.pathname.startsWith(`${prefix}/`)
        ? url.pathname
        : `${prefix}${url.pathname}`;
    return `${path}${url.search}${url.hash}`;
  } catch {
    return location;
  }
}

function rewriteCookie(cookie: string, prefix: string): string {
  if (/;\s*path=/i.test(cookie)) {
    return cookie.replace(/;\s*path=\/[^;]*/i, `; Path=${prefix}/`);
  }
  return `${cookie}; Path=${prefix}/`;
}

export const GET = proxy;
export const POST = proxy;
export const PUT = proxy;
export const PATCH = proxy;
export const DELETE = proxy;
export const HEAD = proxy;
export const OPTIONS = proxy;