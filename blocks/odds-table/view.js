/**
 * Front-end behaviour for the odds table.
 *
 * The only client-side work is switching display format. Every cell already
 * carries its decimal price in a data attribute, so switching is pure local
 * arithmetic: no refetch, no request, no layout shift.
 */

/**
 * Conventional fraction denominators, ascending so the simplest wins ties.
 *
 * Mirrors OddsConverter::FRACTION_DENOMINATORS on the PHP side.
 */
const DENOMINATORS = [
	1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 16, 20, 25, 33, 40, 50, 66, 100,
];

/**
 * Greatest common divisor.
 *
 * @param {number} a First term.
 * @param {number} b Second term.
 * @return {number} Greatest common divisor, at least 1.
 */
function gcd( a, b ) {
	while ( b !== 0 ) {
		[ a, b ] = [ b, a % b ];
	}

	return Math.max( 1, Math.abs( a ) );
}

/**
 * Converts a decimal price to the closest conventional fraction.
 *
 * @param {number} decimal Decimal price.
 * @return {string} Fraction as "n/d".
 */
function toFractional( decimal ) {
	const target = decimal - 1;
	let best = [ 1, 1 ];
	let bestError = Number.MAX_VALUE;

	for ( const denominator of DENOMINATORS ) {
		const numerator = Math.round( target * denominator );

		if ( numerator < 1 ) {
			continue;
		}

		const error = Math.abs( target - numerator / denominator );

		if ( error < bestError - 1e-12 ) {
			bestError = error;
			best = [ numerator, denominator ];
		}
	}

	const divisor = gcd( best[ 0 ], best[ 1 ] );

	return `${ best[ 0 ] / divisor }/${ best[ 1 ] / divisor }`;
}

/**
 * Converts a decimal price to American (moneyline).
 *
 * @param {number} decimal Decimal price.
 * @return {string} Signed moneyline price.
 */
function toAmerican( decimal ) {
	const american =
		decimal >= 2
			? Math.round( ( decimal - 1 ) * 100 )
			: Math.round( -100 / ( decimal - 1 ) );

	return american > 0 ? `+${ american }` : `${ american }`;
}

/**
 * Renders one price in the requested format.
 *
 * @param {number} decimal Decimal price.
 * @param {string} format  Target format.
 * @return {string} Formatted price.
 */
function formatOdds( decimal, format ) {
	if ( format === 'fractional' ) {
		return toFractional( decimal );
	}

	if ( format === 'american' ) {
		return toAmerican( decimal );
	}

	return decimal.toFixed( 2 );
}

/**
 * Applies a format to one table and remembers the choice.
 *
 * @param {Element} table  Block root element.
 * @param {string}  format Target format.
 */
function applyFormat( table, format ) {
	table.querySelectorAll( '.oa-odds' ).forEach( ( cell ) => {
		const decimal = parseFloat( cell.dataset.decimal );

		if ( ! Number.isNaN( decimal ) && decimal > 1 ) {
			cell.textContent = formatOdds( decimal, format );
		}
	} );

	table.dataset.format = format;

	table.querySelectorAll( '.oa-format-button' ).forEach( ( button ) => {
		button.setAttribute( 'aria-pressed', button.dataset.format === format ? 'true' : 'false' );
	} );

	try {
		window.localStorage.setItem( 'oddsAggregatorFormat', format );
	} catch ( error ) {
		// Private mode or blocked storage; the switch still works for this page view.
	}
}

/**
 * The value a row sorts by in one column.
 *
 * The first column is the bookmaker name; every other column is a price, read
 * from the same data-decimal attribute the format switcher works off.
 *
 * @param {HTMLElement} row    Table row.
 * @param {number}      column Zero-based cell index.
 * @return {string|number|null} Comparable value, or null when not quoted.
 */
function sortValue( row, column ) {
	const cell = row.children[ column ];

	if ( ! cell ) {
		return null;
	}

	if ( column === 0 ) {
		return cell.textContent.trim().toLowerCase();
	}

	const odds = cell.querySelector( '.oa-odds' );

	if ( ! odds ) {
		return null;
	}

	const decimal = parseFloat( odds.dataset.decimal );

	return Number.isNaN( decimal ) ? null : decimal;
}

/**
 * Reorders one market table.
 *
 * Passing a null direction restores the order the editor arranged, which is
 * why every row carries its original index.
 *
 * @param {HTMLElement} table     The market table.
 * @param {number}      column    Zero-based cell index to sort by.
 * @param {string|null} direction 'ascending', 'descending' or null to reset.
 */
function sortTable( table, column, direction ) {
	const body = table.querySelector( 'tbody' );

	if ( ! body ) {
		return;
	}

	const rows = Array.from( body.rows );

	rows.sort( ( a, b ) => {
		if ( ! direction ) {
			return Number( a.dataset.order || 0 ) - Number( b.dataset.order || 0 );
		}

		const left = sortValue( a, column );
		const right = sortValue( b, column );

		// A bookmaker that does not quote this outcome sorts last either way.
		if ( left === null && right === null ) {
			return 0;
		}

		if ( left === null ) {
			return 1;
		}

		if ( right === null ) {
			return -1;
		}

		const order = typeof left === 'string' ? left.localeCompare( right ) : left - right;

		return direction === 'ascending' ? order : -order;
	} );

	rows.forEach( ( row ) => body.appendChild( row ) );

	table.querySelectorAll( 'th.oa-sortable' ).forEach( ( heading ) => {
		const button = heading.querySelector( '.oa-sort' );
		const isActive = button && Number( button.dataset.column ) === column && direction;

		heading.setAttribute( 'aria-sort', isActive ? direction : 'none' );
	} );
}

/**
 * Makes a table's headings cycle through descending, ascending and unsorted.
 *
 * Highest first on the first press: on a comparison table the question is
 * almost always which bookmaker pays most.
 *
 * @param {HTMLElement} table The market table.
 */
function bindSorting( table ) {
	let active = null;
	let direction = null;

	table.querySelectorAll( '.oa-sort' ).forEach( ( button ) => {
		button.addEventListener( 'click', () => {
			const column = Number( button.dataset.column );

			if ( active !== column ) {
				active = column;
				direction = 'descending';
			} else if ( direction === 'descending' ) {
				direction = 'ascending';
			} else {
				active = null;
				direction = null;
			}

			sortTable( table, column, direction );
		} );
	} );
}

/**
 * Puts a monogram back when a logo fails to load.
 *
 * Logos are supplied per site, so a URL can rot or 404. The monogram travels
 * with the image to make that recoverable without another request.
 *
 * @param {HTMLImageElement} image The logo that failed.
 */
function restoreMonogram( image ) {
	const monogram = document.createElement( 'span' );

	monogram.className = 'oa-book__monogram';
	monogram.setAttribute( 'aria-hidden', 'true' );
	monogram.style.setProperty( '--oa-monogram-hue', image.dataset.hue || '220' );
	monogram.textContent = image.dataset.monogram || '?';

	image.replaceWith( monogram );
}

/**
 * Wires up every odds table on the page.
 */
function init() {
	const tables = document.querySelectorAll( '.oa-odds-table' );

	if ( ! tables.length ) {
		return;
	}

	let preferred = null;

	try {
		preferred = window.localStorage.getItem( 'oddsAggregatorFormat' );
	} catch ( error ) {
		preferred = null;
	}

	tables.forEach( ( table ) => {
		if ( preferred && preferred !== table.dataset.format ) {
			applyFormat( table, preferred );
		}

		table.querySelectorAll( '.oa-format-button' ).forEach( ( button ) => {
			button.addEventListener( 'click', () => {
				applyFormat( table, button.dataset.format );
			} );
		} );

		table.querySelectorAll( 'table.oa-market' ).forEach( bindSorting );

		table.querySelectorAll( '.oa-book__logo' ).forEach( ( image ) => {
			// An image that already failed fires no further error event.
			if ( image.complete && image.naturalWidth === 0 ) {
				restoreMonogram( image );

				return;
			}

			image.addEventListener( 'error', () => restoreMonogram( image ), { once: true } );
		} );
	} );
}

// Guarded so the conversion helpers can be imported and tested outside a browser.
if ( typeof document !== 'undefined' ) {
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}

export { formatOdds, toFractional, toAmerican, applyFormat, sortTable, sortValue };
