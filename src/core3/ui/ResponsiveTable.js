import { useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import Icon from './icons';
import { Button } from './Button';
import { EmptyState, Skeleton } from './feedback';
import { cx, useUid } from './utils';

/**
 * Sorts rows by a column (numbers numerically, strings with localeCompare).
 *
 * @param {Array}  rows    Rows.
 * @param {Object} column  Column.
 * @param {string} dir     asc | desc.
 * @return {Array} Sorted copy.
 */
export function sortRows( rows, column, dir ) {
	if ( ! column ) {
		return rows;
	}
	const get = column.sortValue || ( ( r ) => r[ column.key ] );
	const factor = dir === 'desc' ? -1 : 1;
	return [ ...rows ].sort( ( a, b ) => {
		const x = get( a );
		const y = get( b );
		if ( x === y ) {
			return 0;
		}
		if ( x === null || x === undefined || x === '' ) {
			return 1;
		}
		if ( y === null || y === undefined || y === '' ) {
			return -1;
		}
		if ( typeof x === 'number' && typeof y === 'number' ) {
			return ( x - y ) * factor;
		}
		return String( x ).localeCompare( String( y ), undefined, { numeric: true, sensitivity: 'base' } ) * factor;
	} );
}

/**
 * Pager: "1–10 of 54" + previous/next.
 *
 * @param {Object}   props              Props.
 * @param {number}   props.page         1-based page.
 * @param {number}   props.perPage      Page size.
 * @param {number}   props.total        Total rows.
 * @param {Function} props.onPageChange ( page ) => void.
 * @return {Element|null} Pager.
 */
export function Pagination( { page, perPage, total, onPageChange } ) {
	const pages = Math.max( 1, Math.ceil( total / perPage ) );
	if ( total <= perPage && page === 1 ) {
		return null;
	}
	const from = total ? ( page - 1 ) * perPage + 1 : 0;
	const to = Math.min( total, page * perPage );
	return (
		<nav className="dev3sh-ui-pager" aria-label={ __( 'Pagination', 'dev3-ai-site-health-analyst' ) }>
			<span className="dev3sh-ui-pager__info">
				{ sprintf( /* translators: 1: first row, 2: last row, 3: total rows */ __( '%1$s–%2$s of %3$s', 'dev3-ai-site-health-analyst' ), from.toLocaleString(), to.toLocaleString(), total.toLocaleString() ) }
			</span>
			<div className="dev3sh-ui-pager__buttons">
				<Button size="sm" icon="chevronLeft" disabled={ page <= 1 } onClick={ () => onPageChange( page - 1 ) } label={ __( 'Previous page', 'dev3-ai-site-health-analyst' ) } />
				<span className="dev3sh-ui-pager__page" aria-current="page">
					{ sprintf( /* translators: 1: page, 2: pages */ __( 'Page %1$s of %2$s', 'dev3-ai-site-health-analyst' ), page, pages ) }
				</span>
				<Button size="sm" icon="chevronRight" disabled={ page >= pages } onClick={ () => onPageChange( page + 1 ) } label={ __( 'Next page', 'dev3-ai-site-health-analyst' ) } />
			</div>
		</nav>
	);
}

/**
 * Data table that becomes stacked cards below 782px (the wp-admin mobile breakpoint).
 *
 * columns: [ { key, label, render( row ), sortable, sortValue( row ), align: 'start'|'end', width,
 *              primary (card title on mobile), hideOnMobile, nowrap } ]
 *
 * Sorting is client-side unless you pass `sort` + `onSortChange` (then sort on the server).
 * Pagination is client-side with `pageSize`, or server-side with `pagination={ { page, perPage, total, onPageChange } }`.
 *
 * @param {Object}   props              Props.
 * @param {Array}    props.columns      Columns.
 * @param {Array}    props.rows         Rows.
 * @param {*}        props.rowKey       Key field name or ( row ) => key.
 * @param {boolean}  props.loading      Skeleton rows.
 * @param {*}        props.empty        Node, or { icon, title, description, action } for EmptyState.
 * @param {string}   props.caption      Accessible caption (visually hidden).
 * @param {Object}   props.sort         { key, dir } (controlled).
 * @param {Function} props.onSortChange ( { key, dir } ) => void.
 * @param {Object}   props.defaultSort  { key, dir } (uncontrolled).
 * @param {number}   props.pageSize     Client-side page size.
 * @param {Object}   props.pagination   Server-side pagination.
 * @param {Function} props.rowActions   ( row ) => node (last column / card footer).
 * @param {Function} props.onRowClick   ( row ) => void.
 * @return {Element} Table.
 */
export function ResponsiveTable( { columns, rows = [], rowKey = 'id', loading = false, empty, caption, sort: controlledSort, onSortChange, defaultSort, pageSize = 0, pagination, rowActions, onRowClick, className } ) {
	const [ ownSort, setOwnSort ] = useState( defaultSort || null );
	const [ ownPage, setOwnPage ] = useState( 1 );
	const sortId = useUid( 'dev3sh-ui-sort' );
	const sort = controlledSort !== undefined ? controlledSort : ownSort;
	const serverSort = controlledSort !== undefined && onSortChange;

	const sorted = useMemo( () => {
		if ( serverSort || ! sort ) {
			return rows;
		}
		return sortRows( rows, columns.find( ( c ) => c.key === sort.key ), sort.dir );
	}, [ rows, sort, serverSort, columns ] );

	const page = pagination ? pagination.page : ownPage;
	const visible = ! pagination && pageSize > 0 ? sorted.slice( ( page - 1 ) * pageSize, page * pageSize ) : sorted;

	const setSort = ( next ) => {
		if ( onSortChange ) {
			onSortChange( next );
		}
		if ( controlledSort === undefined ) {
			setOwnSort( next );
		}
		setOwnPage( 1 );
	};
	const toggleSort = ( col ) => setSort( { key: col.key, dir: sort && sort.key === col.key && sort.dir === 'asc' ? 'desc' : 'asc' } );

	const keyOf = ( row, i ) => ( typeof rowKey === 'function' ? rowKey( row ) : row[ rowKey ] ) ?? i;
	const sortable = columns.filter( ( c ) => c.sortable );
	const allColumns = rowActions ? [ ...columns, { key: '__actions', label: __( 'Actions', 'dev3-ai-site-health-analyst' ), align: 'end', isActions: true } ] : columns;

	const cell = ( col, row ) => {
		if ( col.isActions ) {
			return <div className="dev3sh-ui-table__actions">{ rowActions( row ) }</div>;
		}
		return col.render ? col.render( row ) : row[ col.key ];
	};

	const showEmpty = ! loading && visible.length === 0;
	const emptyNode = empty && ! empty.title && ! empty.description ? empty : <EmptyState compact icon={ ( empty && empty.icon ) || 'inbox' } title={ ( empty && empty.title ) || __( 'Nothing here yet', 'dev3-ai-site-health-analyst' ) } description={ empty && empty.description } action={ empty && empty.action } />;

	return (
		<div className={ cx( 'dev3sh-ui-table-wrap', className ) }>
			{ sortable.length > 0 && ! showEmpty && (
				<div className="dev3sh-ui-table__mobilesort">
					<label htmlFor={ sortId }>{ __( 'Sort by', 'dev3-ai-site-health-analyst' ) }</label>
					<select
						id={ sortId }
						className="dev3sh-ui-input dev3sh-ui-select"
						value={ sort ? `${ sort.key }:${ sort.dir }` : '' }
						onChange={ ( e ) => {
							const [ key, dir ] = e.target.value.split( ':' );
							setSort( key ? { key, dir } : null );
						} }
					>
						<option value="">{ __( 'Default', 'dev3-ai-site-health-analyst' ) }</option>
						{ sortable.map( ( c ) => [
							<option key={ `${ c.key }:asc` } value={ `${ c.key }:asc` }>{ `${ c.label } ↑` }</option>,
							<option key={ `${ c.key }:desc` } value={ `${ c.key }:desc` }>{ `${ c.label } ↓` }</option>,
						] ) }
					</select>
				</div>
			) }
			<table className={ cx( 'dev3sh-ui-table', onRowClick && 'is-clickable' ) } aria-busy={ loading || undefined }>
				{ caption && <caption className="dev3sh-ui-sr-only">{ caption }</caption> }
				<thead>
					<tr>
						{ allColumns.map( ( col ) => {
							const active = sort && sort.key === col.key;
							return (
								<th
									key={ col.key }
									scope="col"
									style={ col.width ? { width: col.width } : undefined }
									className={ cx( col.align === 'end' && 'is-end', col.isActions && 'is-actions' ) }
									aria-sort={ col.sortable ? ( active ? ( sort.dir === 'asc' ? 'ascending' : 'descending' ) : 'none' ) : undefined }
								>
									{ col.sortable ? (
										<button type="button" className={ cx( 'dev3sh-ui-table__sort', active && 'is-active' ) } onClick={ () => toggleSort( col ) }>
											{ col.label }
											<Icon name={ active ? 'chevronDown' : 'sort' } size={ 14 } className={ cx( active && sort.dir === 'asc' && 'is-flipped' ) } />
										</button>
									) : col.isActions ? (
										<span className="dev3sh-ui-sr-only">{ col.label }</span>
									) : (
										col.label
									) }
								</th>
							);
						} ) }
					</tr>
				</thead>
				<tbody>
					{ loading &&
						Array.from( { length: 4 } ).map( ( _, i ) => (
							<tr key={ `s${ i }` } className="is-skeleton">
								{ allColumns.map( ( col ) => (
									<td key={ col.key } data-label={ col.isActions ? '' : col.label }>
										<Skeleton width={ col.isActions ? 48 : '70%' } />
									</td>
								) ) }
							</tr>
						) ) }
					{ showEmpty && (
						<tr className="is-empty">
							<td colSpan={ allColumns.length }>{ emptyNode }</td>
						</tr>
					) }
					{ ! loading &&
						visible.map( ( row, i ) => (
							<tr key={ keyOf( row, i ) } onClick={ onRowClick ? () => onRowClick( row ) : undefined }>
								{ allColumns.map( ( col ) => (
									<td
										key={ col.key }
										data-label={ col.isActions || col.primary ? undefined : col.label }
										className={ cx( col.align === 'end' && 'is-end', col.primary && 'is-primary', col.hideOnMobile && 'is-hide-mobile', col.nowrap && 'is-nowrap', col.isActions && 'is-actions' ) }
									>
										{ cell( col, row ) }
									</td>
								) ) }
							</tr>
						) ) }
				</tbody>
			</table>
			{ pagination ? (
				<Pagination { ...pagination } />
			) : (
				pageSize > 0 && <Pagination page={ page } perPage={ pageSize } total={ sorted.length } onPageChange={ setOwnPage } />
			) }
		</div>
	);
}

export default ResponsiveTable;
