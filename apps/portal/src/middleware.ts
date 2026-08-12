import { NextRequest, NextResponse } from "next/server";

/**
 * Maps {slug}.dev.{host} → /dev/{slug}
 * e.g. demo.dev.example.com → /dev/demo
 *
 * On localhost we prefer path URLs (/dev/{slug}); subdomain rewrites still work
 * but auth must bounce to the apex host (localStorage is origin-scoped).
 */
export function middleware(request: NextRequest) {
  const host = request.headers.get("host") || "";
  const hostname = host.split(":")[0] || "";
  const match = hostname.match(/^([a-z0-9-]+)\.dev\.(.+)$/i);
  if (!match) {
    return NextResponse.next();
  }

  const slug = match[1];
  const apex = match[2].toLowerCase();
  if (!apex || slug === "www") {
    return NextResponse.next();
  }

  const { pathname, search } = request.nextUrl;

  // Portal auth / static assets must not be rewritten under /dev/{slug}/…
  if (
    pathname.startsWith("/dev/") ||
    pathname.startsWith("/_next") ||
    pathname.startsWith("/api") ||
    pathname === "/login" ||
    pathname.startsWith("/login/")
  ) {
    // Subdomain has no shared localStorage → send login to apex portal
    if (pathname === "/login" || pathname.startsWith("/login/")) {
      const proto = request.nextUrl.protocol;
      const port = host.includes(":") ? `:${host.split(":")[1]}` : "";
      const nextTarget = `${proto}//${apex}${port}/dev/${slug}`;
      const login = new URL(`${proto}//${apex}${port}/login`);
      login.searchParams.set("next", nextTarget);
      return NextResponse.redirect(login);
    }
    return NextResponse.next();
  }

  const url = request.nextUrl.clone();
  url.pathname = `/dev/${slug}${pathname === "/" ? "" : pathname}`;
  url.search = search;
  return NextResponse.rewrite(url);
}

export const config = {
  matcher: ["/((?!_next/static|_next/image|favicon.ico).*)"],
};
