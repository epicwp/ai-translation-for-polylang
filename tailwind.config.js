/** @type {import('tailwindcss').Config} */
module.exports = {
  prefix: 'pllat-',
  content: [
    "./assets/scripts/admin/**/*.jsx",
    "./assets/scripts/admin/translation-dashboard/**/*.jsx",
    "./src/Modules/Single_Translator/Handlers/Meta_Box_Handler.php",
    "./src/Modules/Admin/Handlers/Admin_Page_Handler.php",
    "./src/Modules/Settings/Services/Settings_Form.php",
  ],
  theme: {
    extend: {
      animation: {
        spin: "spin 1s linear infinite",
        "pllat-fade": "pllat-fade 180ms ease-out",
        "pllat-pop": "pllat-pop 220ms cubic-bezier(0.16, 1, 0.3, 1)",
        "pllat-rise": "pllat-rise 320ms cubic-bezier(0.16, 1, 0.3, 1) both",
      },
      keyframes: {
        spin: {
          "0%": { transform: "rotate(0deg)" },
          "100%": { transform: "rotate(360deg)" },
        },
        "pllat-fade": {
          "0%": { opacity: "0" },
          "100%": { opacity: "1" },
        },
        "pllat-pop": {
          "0%": { opacity: "0", transform: "scale(0.96) translateY(8px)" },
          "100%": { opacity: "1", transform: "scale(1) translateY(0)" },
        },
        "pllat-rise": {
          "0%": { opacity: "0", transform: "translateY(6px)" },
          "100%": { opacity: "1", transform: "translateY(0)" },
        },
      },
    },
  },
  plugins: [],
};
