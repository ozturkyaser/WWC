/** Build the portal URL for a staging slug (same origin as portal when possible). */
export function stagingPortalUrl(slug: string | null | undefined): string | null {
  if (!slug) return null;

  const build = (protocol: string, hostname: string, port: string) => {
    const host = hostname.replace(/^[^.]+\.dev\./i, "");
    const apex = host.includes(".") || host === "localhost" ? host : "localhost";
    // Path-based on localhost so auth cookie/localStorage matches the portal
    if (apex === "localhost" || apex.endsWith(".localhost")) {
      return `${protocol}//${apex}${port}/dev/${slug}`;
    }
    return `${protocol}//${slug}.dev.${apex}${port}`;
  };

  if (typeof window === "undefined") {
    const base = process.env.NEXT_PUBLIC_PORTAL_URL || "http://localhost:3000";
    try {
      const u = new URL(base);
      const port = u.port ? `:${u.port}` : "";
      return build(u.protocol, u.hostname, port);
    } catch {
      return `http://localhost:3000/dev/${slug}`;
    }
  }

  const { protocol, port, hostname } = window.location;
  return build(protocol, hostname, port ? `:${port}` : "");
}

/** Apex portal origin (strips {slug}.dev. prefix). */
export function portalApexOrigin(): string {
  if (typeof window === "undefined") {
    return process.env.NEXT_PUBLIC_PORTAL_URL || "http://localhost:3000";
  }
  const { protocol, host, hostname } = window.location;
  const apexHost = hostname.replace(/^([a-z0-9-]+)\.dev\./i, "");
  const port = host.includes(":") ? `:${host.split(":")[1]}` : "";
  return `${protocol}//${apexHost}${port}`;
}
