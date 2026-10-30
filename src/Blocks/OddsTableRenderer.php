<?php
/**
 * Server-side block rendering.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Blocks;

use OddsAggregator\Admin\Settings;
use OddsAggregator\Domain\Bookmaker;
use OddsAggregator\Domain\BookmakerRegistry;
use OddsAggregator\Domain\Event;
use OddsAggregator\Domain\Market;
use OddsAggregator\Domain\MarketRegistry;
use OddsAggregator\Domain\Odds;
use OddsAggregator\Domain\OddsLine;
use OddsAggregator\Odds\OddsConverter;
use OddsAggregator\Odds\OddsFormat;
use OddsAggregator\Odds\OddsRepository;

/**
 * Turns cached odds into an accessible comparison table.
 *
 * Rendering happens on the server so the published page needs no JavaScript to
 * show prices, and no request to the REST API. The only client-side work is
 * the format switcher, which recomputes from the decimal value already present
 * on each cell.
 */
final class OddsTableRenderer {

	/**
	 * Constructor.
	 *
	 * @param OddsRepository    $repository Cached odds access.
	 * @param Settings          $settings   Plugin settings.
	 * @param BookmakerRegistry $bookmakers Bookmaker catalogue.
	 * @param MarketRegistry    $markets    Market catalogue.
	 */
	public function __construct(
		private readonly OddsRepository $repository,
		private readonly Settings $settings,
		private readonly BookmakerRegistry $bookmakers,
		private readonly MarketRegistry $markets
	) {}

	/**
	 * Renders the block.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return string
	 */
	public function render( array $attributes ): string {
		$event_id = (string) ( $attributes['eventId'] ?? '' );

		if ( '' === $event_id ) {
			return $this->notice( __( 'Select an event for this odds table.', 'odds-aggregator' ) );
		}

		$event = $this->repository->find_event( $event_id );

		if ( ! $event instanceof Event ) {
			// An event *is* selected, so telling the reader to pick one would be
			// wrong: the data source simply cannot supply it right now.
			return $this->notice(
				__( 'These odds are temporarily unavailable.', 'odds-aggregator' ),
				__( 'The selected event was not returned by the current data source. Check the provider on the Odds Aggregator settings screen.', 'odds-aggregator' )
			);
		}

		$market_keys = $this->resolve_markets( $attributes );
		$markets     = $this->markets->only( $market_keys );

		if ( array() === $markets ) {
			return $this->notice( __( 'No markets are enabled for this odds table.', 'odds-aggregator' ) );
		}

		$lines = $this->repository->odds( $event_id, array_keys( $markets ) );

		if ( array() === $lines ) {
			return $this->notice( __( 'Odds are being refreshed. Please check back shortly.', 'odds-aggregator' ) );
		}

		$bookmakers = $this->resolve_bookmakers( $attributes );

		if ( array() === $bookmakers ) {
			return $this->notice(
				__( 'No odds to show for this table.', 'odds-aggregator' ),
				$this->empty_bookmaker_hint( $attributes )
			);
		}

		$requested = (string) ( $attributes['format'] ?? '' );
		$format    = OddsFormat::from_string_or_default(
			'' === $requested ? $this->settings->default_format() : $requested
		);
		$highlight = (bool) ( $attributes['highlightBest'] ?? true );
		$switcher  = (bool) ( $attributes['showSwitcher'] ?? true );

		$html = sprintf(
			'<div %s data-format="%s">',
			wp_kses_data( get_block_wrapper_attributes( array( 'class' => 'oa-odds-table' ) ) ),
			esc_attr( $format->value )
		);

		$html .= $this->render_event_header( $event );

		if ( $switcher ) {
			$html .= $this->render_switcher( $format );
		}

		foreach ( $markets as $market ) {
			$html .= $this->render_market_table( $event, $market, $lines, $bookmakers, $format, $highlight );
		}

		$html .= $this->render_footer( $lines );
		$html .= '</div>';

		return $html;
	}

	/**
	 * Renders one market's table.
	 *
	 * @param Event                   $event      Event being priced.
	 * @param Market                  $market     Market to render.
	 * @param OddsLine[]              $lines      All price rows for the event.
	 * @param array<string,Bookmaker> $bookmakers Bookmakers to show, in order.
	 * @param OddsFormat              $format     Display format.
	 * @param bool                    $highlight  Whether to mark the best price.
	 * @return string
	 */
	private function render_market_table(
		Event $event,
		Market $market,
		array $lines,
		array $bookmakers,
		OddsFormat $format,
		bool $highlight
	): string {
		$rows = $this->rows_for_market( $lines, $market->key() );

		// Only rows actually shown may win the highlight, otherwise a block
		// displaying a subset of bookmakers could mark none of them best.
		$rows = array_intersect_key( $rows, $bookmakers );

		if ( array() === $rows ) {
			return '';
		}

		$best = $highlight ? $this->best_prices( $rows, $market ) : array();

		$html = '<table class="oa-market">';

		// The event is named once in the header above, so the caption carries
		// only what distinguishes this table from its siblings.
		$html .= '<caption>' . esc_html( $market->label() ) . '</caption>';
		$html .= '<thead><tr>' . $this->sortable_heading( esc_html__( 'Bookmaker', 'odds-aggregator' ), 0 );

		$column = 0;

		foreach ( $market->outcomes() as $outcome ) {
			++$column;
			$html .= $this->sortable_heading( esc_html( $outcome->label() ), $column );
		}

		$html .= '</tr></thead><tbody>';

		$order = 0;

		foreach ( $bookmakers as $bookmaker ) {
			if ( ! isset( $rows[ $bookmaker->id() ] ) ) {
				continue;
			}

			// The configured order is recorded so a reader can sort the table
			// and then put it back the way the editor arranged it.
			$html .= sprintf( '<tr data-order="%d">', $order );
			++$order;

			$html .= '<th scope="row">' . $this->bookmaker_cell( $bookmaker ) . '</th>';

			foreach ( $market->outcomes() as $outcome ) {
				$price   = $rows[ $bookmaker->id() ]->price( $outcome->key() );
				$is_best = $highlight
					&& $price instanceof Odds
					&& isset( $best[ $outcome->key() ] )
					&& abs( $best[ $outcome->key() ] - $price->decimal() ) < 0.0001;
				$html   .= $this->price_cell( $price, $is_best, $format, $outcome->label() );
			}

			$html .= '</tr>';
		}

		$html .= '</tbody></table>';

		return $html;
	}

	/**
	 * Renders a single price cell.
	 *
	 * @param Odds|null  $price   Price, or null when not quoted.
	 * @param bool       $is_best Whether this is the best price for the outcome.
	 * @param OddsFormat $format  Format to render the price in.
	 * @param string     $outcome Outcome label, used as the small-screen row label.
	 * @return string
	 */
	private function price_cell( ?Odds $price, bool $is_best, OddsFormat $format, string $outcome ): string {
		$label = esc_attr( $outcome );

		if ( ! $price instanceof Odds ) {
			return '<td class="oa-price oa-price--none" data-label="' . $label . '"><span aria-label="'
				. esc_attr__( 'Not quoted', 'odds-aggregator' ) . '">&mdash;</span></td>';
		}

		$classes = 'oa-price' . ( $is_best ? ' is-best' : '' );

		/*
		 * Both markers are siblings of .oa-odds, never children of it: the
		 * front-end switcher replaces that span's textContent wholesale, so
		 * anything nested inside would vanish on the first format change.
		 */
		$markers = '';

		if ( $is_best ) {
			$markers = ' <span class="oa-best-badge" aria-hidden="true">'
				. esc_html__( 'Best', 'odds-aggregator' ) . '</span>'
				. ' <span class="screen-reader-text">'
				. esc_html__( '(best price)', 'odds-aggregator' ) . '</span>';
		}

		/*
		 * The visible text is rendered in the chosen format so the block is
		 * correct before any JavaScript runs, while data-decimal stays decimal
		 * because that is what the switcher reformats from.
		 */
		return sprintf(
			'<td class="%s" data-label="%s"><span class="oa-odds" data-decimal="%s">%s</span>%s</td>',
			esc_attr( $classes ),
			$label,
			esc_attr( (string) $price->decimal() ),
			esc_html( OddsConverter::format( $price->decimal(), $format ) ),
			$markers
		);
	}

	/**
	 * Renders a column heading the reader can sort by.
	 *
	 * The heading is a button rather than a clickable cell so it is reachable
	 * by keyboard, and aria-sort on the cell is what assistive technology
	 * reads; the arrow is decorative and driven from that same attribute.
	 *
	 * @param string $label  Escaped heading text.
	 * @param int    $column Zero-based cell index this heading sorts.
	 * @return string
	 */
	private function sortable_heading( string $label, int $column ): string {
		return sprintf(
			'<th scope="col" class="oa-sortable" aria-sort="none">'
				. '<button type="button" class="oa-sort" data-column="%d">%s'
				. '<span class="oa-sort__arrow" aria-hidden="true"></span></button></th>',
			$column,
			$label
		);
	}

	/**
	 * Renders the bookmaker identity cell: its mark followed by its name.
	 *
	 * The whole cell is the outbound link. The logo is decorative, carrying an
	 * empty alt, so the link's accessible name is the bookmaker's name - which
	 * is what someone tabbing or listening through the table expects to hear.
	 *
	 * @param Bookmaker $bookmaker Bookmaker.
	 * @return string
	 */
	private function bookmaker_cell( Bookmaker $bookmaker ): string {
		$inner = $this->bookmaker_mark( $bookmaker )
			. '<span class="oa-book__name">' . esc_html( $bookmaker->name() ) . '</span>';

		if ( '' === $bookmaker->url() ) {
			return '<span class="oa-book">' . $inner . '</span>';
		}

		return sprintf(
			'<a class="oa-book oa-book--link" href="%s" rel="nofollow sponsored noopener" target="_blank">%s</a>',
			esc_url( $bookmaker->url() ),
			$inner
		);
	}

	/**
	 * The administrator-supplied logo, or a monogram standing in for it.
	 *
	 * The plugin bundles no logos: shipping third-party trademarks is not ours
	 * to do. An unset logo therefore falls back to the bookmaker's initials.
	 *
	 * @param Bookmaker $bookmaker Bookmaker.
	 * @return string
	 */
	private function bookmaker_mark( Bookmaker $bookmaker ): string {
		if ( '' !== $bookmaker->logo() ) {
			return sprintf(
				'<img class="oa-book__logo" src="%s" alt="" loading="lazy" decoding="async" />',
				esc_url( $bookmaker->logo() )
			);
		}

		// Derived from the id rather than picked at random, so a brand keeps
		// the same colour between page loads and between pages.
		$hue = absint( crc32( $bookmaker->id() ) ) % 360;

		return sprintf(
			'<span class="oa-book__monogram" style="--oa-monogram-hue:%d" aria-hidden="true">%s</span>',
			$hue,
			esc_html( $this->monogram( $bookmaker->name() ) )
		);
	}

	/**
	 * One or two initials standing in for a missing logo.
	 *
	 * @param string $name Display name.
	 * @return string
	 */
	private function monogram( string $name ): string {
		$words = preg_split( '/[^A-Za-z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY );

		if ( ! is_array( $words ) || array() === $words ) {
			return '?';
		}

		$initials = strtoupper( substr( (string) $words[0], 0, 1 ) );

		if ( isset( $words[1] ) ) {
			$initials .= strtoupper( substr( (string) $words[1], 0, 1 ) );
		}

		return $initials;
	}

	/**
	 * Renders the event heading shown above the market tables.
	 *
	 * @param Event $event Event being priced.
	 * @return string
	 */
	private function render_event_header( Event $event ): string {
		$kickoff = $event->kickoff();

		return sprintf(
			'<header class="oa-event"><span class="oa-event__title">%s</span><time class="oa-event__kickoff" datetime="%s">%s</time></header>',
			esc_html( $event->title() ),
			esc_attr( $kickoff->format( DATE_ATOM ) ),
			esc_html( wp_date( 'j M, H:i', $kickoff->getTimestamp() ) )
		);
	}

	/**
	 * Renders the format switcher.
	 *
	 * @param OddsFormat $current Active format.
	 * @return string
	 */
	private function render_switcher( OddsFormat $current ): string {
		$html = '<div class="oa-switcher" role="group" aria-label="'
			. esc_attr__( 'Odds format', 'odds-aggregator' ) . '">';

		foreach ( OddsFormat::cases() as $format ) {
			$html .= sprintf(
				'<button type="button" class="oa-format-button" data-format="%s" aria-pressed="%s">%s</button>',
				esc_attr( $format->value ),
				$format === $current ? 'true' : 'false',
				esc_html( $format->label() )
			);
		}

		return $html . '</div>';
	}

	/**
	 * Renders the freshness note.
	 *
	 * Showing the age is a deliberate accuracy measure: stale odds are useful,
	 * but only if the reader knows they are stale.
	 *
	 * @param OddsLine[] $lines Price rows.
	 * @return string
	 */
	private function render_footer( array $lines ): string {
		$timestamps = array_filter( array_map( static fn( OddsLine $line ): int => $line->fetched_at(), $lines ) );

		if ( array() === $timestamps ) {
			return '';
		}

		$fetched = max( $timestamps );

		$relative = sprintf(
			'<time datetime="%s">%s</time>',
			esc_attr( gmdate( DATE_ATOM, $fetched ) ),
			esc_html( human_time_diff( $fetched, time() ) )
		);

		return '<p class="oa-updated">' . sprintf(
			/* translators: %s: human readable time difference, e.g. "2 mins". */
			esc_html__( 'Odds updated %s ago. Always check the bookmaker before betting.', 'odds-aggregator' ),
			$relative
		) . '</p>';
	}

	/**
	 * Indexes price rows for one market by bookmaker id.
	 *
	 * @param OddsLine[] $lines      All rows.
	 * @param string     $market_key Market key.
	 * @return array<string,OddsLine>
	 */
	private function rows_for_market( array $lines, string $market_key ): array {
		$rows = array();

		foreach ( $lines as $line ) {
			if ( $line->market_key() === $market_key ) {
				$rows[ $line->bookmaker_id() ] = $line;
			}
		}

		return $rows;
	}

	/**
	 * Best available decimal price per outcome.
	 *
	 * @param array<string,OddsLine> $rows   Rows for one market.
	 * @param Market                 $market Market being rendered.
	 * @return array<string,float>
	 */
	private function best_prices( array $rows, Market $market ): array {
		$best = array();

		foreach ( $market->outcome_keys() as $outcome_key ) {
			foreach ( $rows as $row ) {
				$price = $row->price( $outcome_key );

				if ( ! $price instanceof Odds ) {
					continue;
				}

				if ( ! isset( $best[ $outcome_key ] ) || $price->decimal() > $best[ $outcome_key ] ) {
					$best[ $outcome_key ] = $price->decimal();
				}
			}
		}

		return $best;
	}

	/**
	 * Markets for this block: the block's choice, narrowed to what is enabled.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return string[]
	 */
	private function resolve_markets( array $attributes ): array {
		$enabled  = $this->settings->markets();
		$selected = array_map( 'strval', (array) ( $attributes['markets'] ?? array() ) );

		if ( array() === $selected ) {
			return $enabled;
		}

		return array_values( array_intersect( $selected, $enabled ) );
	}

	/**
	 * Bookmakers for this block: the block's choice, narrowed to what is enabled.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return array<string,Bookmaker>
	 */
	private function resolve_bookmakers( array $attributes ): array {
		$enabled  = $this->settings->bookmakers();
		$selected = array_map( 'strval', (array) ( $attributes['bookmakers'] ?? array() ) );
		$ids      = array() === $selected ? $enabled : array_values( array_intersect( $selected, $enabled ) );

		return $this->bookmakers->only( $ids );
	}

	/**
	 * Explains to an administrator why no bookmakers resolved.
	 *
	 * A block may pin its own bookmakers. Site settings are a ceiling, so
	 * pinning names that are all switched off leaves nothing to render - and
	 * saying "none are enabled" would be wrong when others clearly are.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return string
	 */
	private function empty_bookmaker_hint( array $attributes ): string {
		$pinned = array_filter( array_map( 'strval', (array) ( $attributes['bookmakers'] ?? array() ) ) );

		if ( array() === $pinned ) {
			return __(
				'No bookmakers are enabled on the Odds Aggregator settings screen.',
				'odds-aggregator'
			);
		}

		$names = array();

		foreach ( $pinned as $id ) {
			$bookmaker = $this->bookmakers->get( $id );
			$names[]   = $bookmaker instanceof Bookmaker ? $bookmaker->name() : $id;
		}

		return sprintf(
			/* translators: %s: comma-separated bookmaker names pinned to this block. */
			__(
				'This block is pinned to %s, and none of those are enabled on the Odds Aggregator settings screen. Either enable one of them, or remove them from the block so it follows the site settings.',
				'odds-aggregator'
			),
			implode( ', ', $names )
		);
	}

	/**
	 * Renders an inline notice in place of a table.
	 *
	 * @param string $message    Message shown to everyone.
	 * @param string $admin_hint Extra diagnostic shown only to administrators.
	 * @return string
	 */
	private function notice( string $message, string $admin_hint = '' ): string {
		$html = sprintf(
			'<div %s><p class="oa-notice">%s</p>',
			wp_kses_data( get_block_wrapper_attributes( array( 'class' => 'oa-odds-table' ) ) ),
			esc_html( $message )
		);

		// Diagnostics belong to whoever can act on them, not to every visitor.
		if ( '' !== $admin_hint && current_user_can( 'manage_options' ) ) {
			$html .= '<p class="oa-notice"><em>' . esc_html( $admin_hint ) . '</em></p>';
		}

		return $html . '</div>';
	}
}
