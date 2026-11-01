import { createElement, render } from '@wordpress/element';
import * as element from '@wordpress/element';
import App from '../admin/App';
import '../core3/admin-kit.css';

const node = document.getElementById( 'dev3sh-admin-root' );
if ( node ) {
	if ( element.createRoot ) {
		element.createRoot( node ).render( createElement( App ) );
	} else {
		render( createElement( App ), node );
	}
}
