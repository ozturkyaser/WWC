import type { Metadata } from "next";
import "./globals.css";

export const metadata: Metadata = {
  title: "WWC Wartungsportal",
  description: "WordPress-Wartung und Security Management",
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="de" suppressHydrationWarning>
      <body suppressHydrationWarning>{children}</body>
    </html>
  );
}
