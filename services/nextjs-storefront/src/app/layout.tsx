import type { Metadata } from 'next';
import './globals.css';

export const metadata: Metadata = {
  title: 'OmniTix',
  description: 'All-in-one ticketing across every channel and device.',
};

export default function RootLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <html lang="en">
      <body className="min-h-screen bg-white text-slate-900 dark:bg-slate-950 dark:text-slate-50">
        <header className="border-b border-slate-200 dark:border-slate-800">
          <div className="mx-auto flex max-w-6xl items-center justify-between p-4">
            <a href="/" className="text-xl font-bold tracking-tight">
              OmniTix
            </a>
            <nav className="flex gap-6 text-sm">
              <a href="/" className="hover:text-brand-600">
                Events
              </a>
              <a href="/account/lookup" className="hover:text-brand-600">
                My tickets
              </a>
            </nav>
          </div>
        </header>

        <main className="mx-auto max-w-6xl px-4 py-8">{children}</main>

        <footer className="border-t border-slate-200 py-6 text-center text-sm text-slate-500 dark:border-slate-800">
          © {new Date().getFullYear()} OmniTix
        </footer>
      </body>
    </html>
  );
}