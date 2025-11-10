import forms from '@tailwindcss/forms';
import typography from '@tailwindcss/typography';

export default {
  content: [
    './views/**/*.twig',
    './*.php',
    './src/**/*.{js,jsx,ts,tsx,twig}',
  ],
  theme: {
    extend: {
      colors: {
        brand: {
          orange: '#ef7c25',
          white: '#ffffff',
          charcoal: '#1c1d1f',
          sand: '#f5f5f0',
          cloud: '#f8fafc',
        },
      },
      fontFamily: {
        sans: ['"Work Sans"', 'ui-sans-serif', 'system-ui', 'sans-serif'],
      },
      backgroundImage: {
        'hero-pattern': 'radial-gradient(circle at 20% 20%, rgba(255, 255, 255, 0.35), transparent 55%)',
        'accent-glow': 'radial-gradient(circle at 80% 10%, rgba(255, 255, 255, 0.25), transparent 60%)',
      },
      boxShadow: {
        focus: '0 0 0 3px rgba(239, 124, 37, 0.35)',
      },
    },
  },
  plugins: [forms, typography],
};

