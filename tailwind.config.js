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
          wood: '#A47551',      // warm wood brown (primary)
          pine: '#708D81',       // soft pine green (secondary)
          birch: '#F8F5F1',      // birch beige (background)
          charcoal: '#2F2F2F',   // dark charcoal (text)
          mist: '#9FBCC1',       // misty blue (accent)
          white: '#ffffff',
        },
      },
      fontFamily: {
        serif: ['"Playfair Display"', 'Georgia', 'serif'],
        sans: ['"Inter"', '"Source Sans Pro"', 'ui-sans-serif', 'system-ui', 'sans-serif'],
      },
      backgroundImage: {
        'hero-pattern': 'radial-gradient(circle at 20% 20%, rgba(255, 255, 255, 0.35), transparent 55%)',
        'accent-glow': 'radial-gradient(circle at 80% 10%, rgba(255, 255, 255, 0.25), transparent 60%)',
        'wood-grain': 'url("data:image/svg+xml,%3Csvg width=\'60\' height=\'60\' viewBox=\'0 0 60 60\' xmlns=\'http://www.w3.org/2000/svg\'%3E%3Cg fill=\'none\' fill-rule=\'evenodd\'%3E%3Cg fill=\'%23A47551\' fill-opacity=\'0.03\'%3E%3Cpath d=\'M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z\'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E")',
      },
      borderRadius: {
        'soft': '1rem',
      },
      boxShadow: {
        focus: '0 0 0 3px rgba(164, 117, 81, 0.35)',
        'soft': '0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06)',
      },
    },
  },
  plugins: [forms, typography],
};

