/* JSX runtime shim for WordPress < 6.6, which does not register the react-jsx-runtime handle. */
( function () {
	if ( window.ReactJSXRuntime ) {
		return;
	}
	var React = window.React;
	function jsx( type, props, key ) {
		var copy = {};
		for ( var k in props ) {
			if ( Object.prototype.hasOwnProperty.call( props, k ) && k !== 'children' ) {
				copy[ k ] = props[ k ];
			}
		}
		if ( key !== undefined ) {
			copy.key = key;
		}
		var children = props ? props.children : undefined;
		if ( children === undefined ) {
			return React.createElement( type, copy );
		}
		return Array.isArray( children ) ? React.createElement.apply( null, [ type, copy ].concat( children ) ) : React.createElement( type, copy, children );
	}
	window.ReactJSXRuntime = { jsx: jsx, jsxs: jsx, jsxDEV: jsx, Fragment: React.Fragment };
} )();
