import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // Clone-Proxy: WordPress/Apache 301t oft auf /clone/{port}/, Next würde
  // sonst mit 308 zurück auf ohne Slash schicken → Endlosschleife.
  skipTrailingSlashRedirect: true,
};

export default nextConfig;
