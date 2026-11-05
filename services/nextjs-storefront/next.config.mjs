/** @type {import('next').NextConfig} */
const nextConfig = {
  output: 'standalone',
  reactStrictMode: true,
  poweredByHeader: false,
  // Allow importing SVG files as components (for icons later)
  webpack(config) {
    return config;
  },
  // Rewrite /api/* to Laravel, /ws to NestJS — makes the browser see one origin
  async rewrites() {
    return [
      {
        source: '/api/laravel/:path*',
        destination: `${process.env.NEXT_PUBLIC_LARAVEL_URL ?? 'http://localhost:8000'}/api/:path*`,
      },
      {
        source: '/api/inventory/:path*',
        destination: `${process.env.NEXT_PUBLIC_INVENTORY_URL ?? 'http://localhost:8080'}/api/:path*`,
      },
    ];
  },
};

export default nextConfig;