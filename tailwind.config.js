/** @type {import('tailwindcss').Config} */
module.exports = {
	corePlugins: {
		preflight: false,
	},
	content: [ './dev_gemcloud/**/*.{js,jsx}', './assets/scss/**/*.scss' ],
	important: '.gemcloud-scope',
	theme: {
		extend: {},
	},
	plugins: [],
};
