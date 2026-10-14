/** @type {import('tailwindcss').Config} */
module.exports = {
	corePlugins: {
		preflight: false,
	},
	content: [ './dev_gemcloudstorage/**/*.{js,jsx}', './assets/scss/**/*.scss' ],
	important: '.gemcloudstorage-scope',
	theme: {
		extend: {},
	},
	plugins: [],
};
