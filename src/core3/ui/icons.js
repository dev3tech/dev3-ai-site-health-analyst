import { cx } from './utils';

/* 24×24 stroke icons (Lucide-style geometry, drawn for the kit). */
const PATHS = {
	sparkles: 'M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9L12 3zM19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8L19 15zM5 15l.6 1.4L7 17l-1.4.6L5 19l-.6-1.4L3 17l1.4-.6L5 15z',
	dashboard: 'M3 3h7v9H3zM14 3h7v5h-7zM14 12h7v9h-7zM3 16h7v5H3z',
	settings: 'M12 15a3 3 0 100-6 3 3 0 000 6zM19.4 15a1.7 1.7 0 00.3 1.8l.1.1a2 2 0 11-2.8 2.8l-.1-.1a1.7 1.7 0 00-1.8-.3 1.7 1.7 0 00-1 1.5V21a2 2 0 11-4 0v-.1a1.7 1.7 0 00-1.1-1.5 1.7 1.7 0 00-1.8.3l-.1.1a2 2 0 11-2.8-2.8l.1-.1a1.7 1.7 0 00.3-1.8 1.7 1.7 0 00-1.5-1H3a2 2 0 110-4h.1a1.7 1.7 0 001.5-1.1 1.7 1.7 0 00-.3-1.8l-.1-.1a2 2 0 112.8-2.8l.1.1a1.7 1.7 0 001.8.3H9a1.7 1.7 0 001-1.5V3a2 2 0 114 0v.1a1.7 1.7 0 001 1.5 1.7 1.7 0 001.8-.3l.1-.1a2 2 0 112.8 2.8l-.1.1a1.7 1.7 0 00-.3 1.8V9a1.7 1.7 0 001.5 1H21a2 2 0 110 4h-.1a1.7 1.7 0 00-1.5 1z',
	key: 'M21 2l-2 2m-7.6 7.6a5.5 5.5 0 11-7.8 7.8 5.5 5.5 0 017.8-7.8zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4',
	chart: 'M3 3v18h18M7 16v-5M12 16V8M17 16v-8',
	list: 'M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01',
	plus: 'M12 5v14M5 12h14',
	check: 'M20 6L9 17l-5-5',
	x: 'M18 6L6 18M6 6l12 12',
	alert: 'M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0zM12 9v4M12 17h.01',
	info: 'M12 22a10 10 0 100-20 10 10 0 000 20zM12 16v-4M12 8h.01',
	success: 'M22 11.1V12a10 10 0 11-5.9-9.1M22 4L12 14l-3-3',
	error: 'M12 22a10 10 0 100-20 10 10 0 000 20zM15 9l-6 6M9 9l6 6',
	chevronDown: 'M6 9l6 6 6-6',
	chevronLeft: 'M15 18l-6-6 6-6',
	chevronRight: 'M9 18l6-6-6-6',
	sort: 'M7 15l5 5 5-5M7 9l5-5 5 5',
	search: 'M11 19a8 8 0 100-16 8 8 0 000 16zM21 21l-4.3-4.3',
	refresh: 'M23 4v6h-6M1 20v-6h6M3.5 9a9 9 0 0114.8-3.4L23 10M1 14l4.7 4.4A9 9 0 0020.5 15',
	external: 'M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6M15 3h6v6M10 14L21 3',
	trash: 'M3 6h18M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2m3 0v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6h14z',
	edit: 'M12 20h9M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4 12.5-12.5z',
	inbox: 'M22 12h-6l-2 3h-4l-2-3H2M5.5 5.1L2 12v6a2 2 0 002 2h16a2 2 0 002-2v-6l-3.5-6.9A2 2 0 0016.7 4H7.3a2 2 0 00-1.8 1.1z',
	clock: 'M12 22a10 10 0 100-20 10 10 0 000 20zM12 6v6l4 2',
	pause: 'M10 15V9M14 15V9M12 22a10 10 0 100-20 10 10 0 000 20z',
	menu: 'M3 12h18M3 6h18M3 18h18',
	copy: 'M20 9h-9a2 2 0 00-2 2v9a2 2 0 002 2h9a2 2 0 002-2v-9a2 2 0 00-2-2zM5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1',
	help: 'M12 22a10 10 0 100-20 10 10 0 000 20zM9.1 9a3 3 0 015.8 1c0 2-3 3-3 3M12 17h.01',
	columns: 'M12 3v18M3 5a2 2 0 012-2h14a2 2 0 012 2v14a2 2 0 01-2 2H5a2 2 0 01-2-2z',
	diff: 'M12 3v14M5 10h14M5 21h14',
	file: 'M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8zM14 2v6h6',
	box: 'M21 16V8a2 2 0 00-1-1.7l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.7l7 4a2 2 0 002 0l7-4a2 2 0 001-1.7zM3.3 7L12 12l8.7-5M12 22V12',
	users: 'M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2M9 11a4 4 0 100-8 4 4 0 000 8zM23 21v-2a4 4 0 00-3-3.9M16 3.1a4 4 0 010 7.8',
	bolt: 'M13 2L3 14h9l-1 8 10-12h-9l1-8z',
};

export const iconNames = Object.keys( PATHS );

/**
 * Inline SVG icon.
 *
 * @param {Object} props           Props.
 * @param {string} props.name      Icon name (see iconNames).
 * @param {number} props.size      Pixels (default 16).
 * @param {string} props.label     Accessible label; omit for decorative icons.
 * @param {string} props.className Extra classes.
 * @return {Element} Icon.
 */
export default function Icon( { name, size = 16, label, className } ) {
	const d = PATHS[ name ] || PATHS.sparkles;
	const filled = name === 'sparkles' || name === 'bolt';
	return (
		<svg
			className={ cx( 'dev3sh-ui-icon', className ) }
			width={ size }
			height={ size }
			viewBox="0 0 24 24"
			fill={ filled ? 'currentColor' : 'none' }
			stroke={ filled ? 'none' : 'currentColor' }
			strokeWidth="2"
			strokeLinecap="round"
			strokeLinejoin="round"
			role={ label ? 'img' : undefined }
			aria-label={ label || undefined }
			aria-hidden={ label ? undefined : true }
			focusable="false"
		>
			<path d={ d } />
		</svg>
	);
}

/**
 * Default product mark (gradient tile with sparkles), used when a plugin passes no icon.
 *
 * @param {Object} props      Props.
 * @param {number} props.size Pixels.
 * @return {Element} Mark.
 */
export function BrandMark( { size = 36 } ) {
	return (
		<svg width={ size } height={ size } viewBox="0 0 128 128" aria-hidden="true" focusable="false" className="dev3sh-ui-brandmark">
			<defs>
				<linearGradient id="dev3sh-ui-mark" x1="0" y1="0" x2="1" y2="1">
					<stop offset="0" stopColor="#4f46e5" />
					<stop offset="1" stopColor="#0891b2" />
				</linearGradient>
			</defs>
			<rect width="128" height="128" rx="28" fill="url(#dev3sh-ui-mark)" />
			<path fill="#fff" d="M64 18l9 25 25 9-25 9-9 25-9-25-25-9 25-9 9-25zm30 52l4 11 11 4-11 4-4 11-4-11-11-4 11-4 4-11zM34 78l3 8 8 3-8 3-3 8-3-8-8-3 8-3 3-8z" />
		</svg>
	);
}
