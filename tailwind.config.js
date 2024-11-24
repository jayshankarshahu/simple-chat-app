/** @type {import('tailwindcss').Config} */
module.exports = {
  content: ["./templates/**/*.{html,js}"],
  theme: {
    extend: {
      colors: {
        custom: {
          darkest: '#000000',   // Pure black for the main background
          dark: '#1A1D21',      // Deep charcoal gray for containers or modals
          primary: '#2E3A40',   // Muted charcoal blue for primary elements
          secondary: '#47575E', // Steel blue-gray for secondary elements
          light: '#A4B2BC',     // Soft, desaturated light blue-gray for highlights
        },
      },
    }
  },
  plugins: [],
};
