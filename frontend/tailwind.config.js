/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{vue,js,ts,jsx,tsx}'],
  theme: {
    extend: {
      colors: {
        premium: {
          bg: '#0B0F14',
          card: '#11161D',
          border: '#1F2937',
          primary: '#22C55E',
          danger: '#EF4444',
          neutral: '#9CA3AF',
        },
      },
      boxShadow: {
        soft: '0 10px 30px rgba(0, 0, 0, 0.26)',
      },
    },
  },
  plugins: [require('@tailwindcss/forms')],
}
