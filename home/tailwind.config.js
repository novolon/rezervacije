
/** @type {import('tailwindcss').Config} */
export default {
  content: [
  './index.html',
  './src/**/*.{js,ts,jsx,tsx}'
],
  theme: {
    extend: {
      colors: {
        forest: {
          DEFAULT: '#1B4332',
          light: '#2D6A4F',
          dark: '#081C15',
        },
        cream: {
          DEFAULT: '#FAFAF5',
          dark: '#F0F0E6',
        },
        terracotta: {
          DEFAULT: '#C4704B',
          hover: '#A85D3B',
        },
        sage: {
          DEFAULT: '#A3B18A',
          light: '#DAD7CD',
        }
      },
      fontFamily: {
        sans: ['"DM Sans"', 'sans-serif'],
      },
    },
  },
  plugins: [],
}
