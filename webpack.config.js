const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const fs = require( 'fs' );
const path = require( 'path' );

// Every file in src/entries/ becomes build/<name>.js (+ .asset.php, .css).
const dir = path.resolve( __dirname, 'src/entries' );
const entry = Object.fromEntries(
	fs.readdirSync( dir ).filter( ( f ) => f.endsWith( '.js' ) ).map( ( f ) => [ path.basename( f, '.js' ), path.join( dir, f ) ] )
);

module.exports = { ...defaultConfig, entry, output: { ...defaultConfig.output, path: path.resolve( __dirname, 'build' ) } };
