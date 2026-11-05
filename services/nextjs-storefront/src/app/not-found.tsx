import Link from 'next/link';

export default function NotFound() {
  return (
    <div className="space-y-4 text-center py-16">
      <h1 className="text-3xl font-bold">Page not found</h1>
      <p className="text-slate-600 dark:text-slate-400">
        The page you're looking for doesn't exist.
      </p>
      <Link
        href="/"
        className="inline-block rounded-md bg-brand-600 px-6 py-3 font-medium text-white hover:bg-brand-700"
      >
        Back to events
      </Link>
    </div>
  );
}