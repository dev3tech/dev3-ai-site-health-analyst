const fs = require( 'fs' );
const path = require( 'path' );
const defaults = require( '@wordpress/scripts/config/jest-unit.config' );

// WordPress packages are webpack externals (window.wp.*). Tests use the real @wordpress/element and
// @wordpress/i18n from node_modules, and a mock for @wordpress/api-fetch (tests/js/mocks/api-fetch.js:
// `apiFetch.mockResolvedValueOnce( … )`). tests/js/setup.js sets the boot config and the React act() flag.
const mock = path.join( __dirname, 'tests/js/mocks/api-fetch.js' );
const setup = path.join( __dirname, 'tests/js/setup.js' );

module.exports = {
	...defaults,
	testMatch: [ '**/tests/js/**/*.test.js' ],
	setupFiles: fs.existsSync( setup ) ? [ setup ] : [],
	moduleNameMapper: fs.existsSync( mock ) ? { '^@wordpress/api-fetch$': mock } : {},
};
