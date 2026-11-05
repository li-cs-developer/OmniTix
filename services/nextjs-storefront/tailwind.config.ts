import type { Config } from 'tailwindcss';

const config: Config = {
  content: ['./src/**/*.{ts,tsx}'],
  theme: {
    extend: {
      colors: {
        brand: {
          50: '#f5f3ff',
          100: '#ede9fe',
          200: '#ddd6fe',
          300: '#c4b5fd',
          400: '#a78bfa',
          500: '#8b5cf6',
          600: '#6c5ce7',
          700: '#5b21b6',
          800: '#4c1d95',
          900: '#3b0764',
        },
        seat: {
          available: '#6c5ce7',
          held: '#fdcb6e',
          sold: '#636e72',
          selected: '#00b894',
          blocked: '#2d3436',
        },
      },
      fontFamily: {
        sans: ['Inter', 'system-ui', 'sans-serif'],
      },
    },
  },
  plugins: [],
};

export default config;