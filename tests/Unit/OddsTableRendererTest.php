<?php
/**
 * Tests for the server-side block renderer.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Tests\Unit;

use OddsAggregator\Admin\Settings;
use OddsAggregator\Blocks\OddsTableRenderer;
use OddsAggregator\Cache\OddsCache;
use OddsAggregator\Domain\BookmakerRegistry;
use OddsAggregator\Domain\MarketRegistry;
use OddsAggregator\Http\HttpClient;
use OddsAggregator\Http\RateLimiter;
use OddsAggregator\Http\RetryPolicy;
use OddsAggregator\Odds\OddsRepository;
use OddsAggregator\Providers\ProviderFactory;
use PHPUnit\Framework\TestCase;

/**
 * Covers the markup the front end actually receives.
 *
 * Built from real collaborators over the sample provider rather than doubles:
 * the renderer's job is to turn repository output into HTML, so stubbing the
 * repository would test almost nothing. Sample data is deterministic, so the
 * prices are stable.
 */
final class OddsTableRendererTest extends TestCase {

	/**
	 * Bookmakers the sample provider quotes, narrowed to keep assertions small.
	 *
	 * @var string[]
	 */
	private const SHOWN = array( 'bet365', 'williamhill', 'paddypower' );

	/**
	 * Resets the stores the stubs are backed by.
	 */
	protected function setUp(): void {
		$GLOBALS['odds_aggregator_test_options']    = array();
		$GLOBALS['odds_aggregator_test_transients'] = array();
		$GLOBALS['odds_aggregator_test_can']        = false;
	}

	/**
	 * A renderer wired to the sample provider.
	 *
	 * @param array<string,mixed> $stored Settings overrides.
	 * @return OddsTableRenderer
	 */
	private function renderer( array $stored = array() ): OddsTableRenderer {
		$GLOBALS['odds_aggregator_test_options'] = array(
			Settings::OPTION => array_merge(
				Settings::defaults(),
				array(
					'provider'   => 'mock',
					'bookmakers' => self::SHOWN,
					'markets'    => array( '1x2' ),
				),
				$stored
			),
		);

		$settings = new Settings();
		$markets  = new MarketRegistry();
		$http     = new HttpClient( new RateLimiter(), new RetryPolicy() );

		$repository = new OddsRepository(
			new ProviderFactory( $settings, $http ),
			new OddsCache( $settings->cache_ttl(), $settings->stale_ttl() ),
			$markets
		);

		return new OddsTableRenderer(
			$repository,
			$settings,
			new BookmakerRegistry( $settings->bookmaker_urls(), $settings->bookmaker_logos() ),
			$markets
		);
	}

	/**
	 * Renders the first sample event.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @param array<string,mixed> $stored     Settings overrides.
	 * @return string
	 */
	private function render( array $attributes = array(), array $stored = array() ): string {
		return $this->renderer( $stored )->render(
			array_merge( array( 'eventId' => 'eng-ars-che' ), $attributes )
		);
	}

	/**
	 * The chosen format is rendered server-side, not patched in by JavaScript.
	 *
	 * The visible text used to be hard-coded to decimal whatever the block was
	 * set to, so a fractional block showed decimals until the switcher script
	 * ran - and showed them forever with JavaScript disabled.
	 */
	public function test_prices_render_in_the_chosen_format(): void {
		$html = $this->render( array( 'format' => 'fractional' ) );

		$this->assertMatchesRegularExpression(
			'#<span class="oa-odds" data-decimal="[\d.]+">\d+/\d+</span>#',
			$html
		);
	}

	/**
	 * The data-decimal attribute stays decimal whatever is displayed.
	 *
	 * It is what the front-end switcher reformats from, so it must not follow
	 * the chosen format.
	 */
	public function test_data_decimal_is_unaffected_by_the_display_format(): void {
		$fractional = $this->render( array( 'format' => 'fractional' ) );
		$american   = $this->render( array( 'format' => 'american' ) );

		$this->assertSame(
			$this->decimals( $fractional ),
			$this->decimals( $american )
		);
		$this->assertNotSame( array(), $this->decimals( $fractional ) );
	}

	/**
	 * Decimal remains the default rendering.
	 */
	public function test_decimal_is_rendered_by_default(): void {
		$this->assertMatchesRegularExpression(
			'#<span class="oa-odds" data-decimal="[\d.]+">\d+\.\d+</span>#',
			$this->render()
		);
	}

	/**
	 * A configured logo is used, and a monogram stands in when none is set.
	 */
	public function test_logo_falls_back_to_a_monogram(): void {
		$html = $this->render(
			array(),
			array( 'bookmaker_logos' => array( 'bet365' => 'https://example.test/bet365.svg' ) )
		);

		$this->assertStringContainsString(
			'<img class="oa-book__logo" src="https://example.test/bet365.svg"',
			$html
		);

		// William Hill has no logo configured, so it gets its initials.
		$this->assertStringContainsString( '>WH</span>', $html );
	}

	/**
	 * The monogram colour is derived from the id, so it is stable.
	 */
	public function test_monogram_colour_is_stable_across_renders(): void {
		$first  = $this->render();
		$second = $this->render();

		$this->assertSame( $this->hues( $first ), $this->hues( $second ) );
		$this->assertNotSame( array(), $this->hues( $first ) );
	}

	/**
	 * The bookmaker's name is the outbound link, and the admin URL wins.
	 */
	public function test_bookmaker_name_links_out(): void {
		$html = $this->render(
			array(),
			array( 'bookmaker_urls' => array( 'bet365' => 'https://example.test/go' ) )
		);

		$this->assertStringContainsString(
			'<a class="oa-book oa-book--link" href="https://example.test/go" rel="nofollow sponsored noopener"',
			$html
		);

		// One link per shown bookmaker, each carrying the affiliate rel.
		$this->assertSame( count( self::SHOWN ), substr_count( $html, 'oa-book--link' ) );
		$this->assertSame( count( self::SHOWN ), substr_count( $html, 'rel="nofollow sponsored noopener"' ) );
	}

	/**
	 * The best price is marked visually and for screen readers.
	 */
	public function test_best_price_carries_a_badge(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'oa-best-badge', $html );
		$this->assertStringContainsString( '(best price)', $html );

		// The badge must never sit inside .oa-odds: the switcher overwrites
		// that element's text content and would delete it.
		$this->assertDoesNotMatchRegularExpression(
			'#<span class="oa-odds"[^>]*>[^<]*<span class="oa-best-badge"#',
			$html
		);
	}

	/**
	 * Highlighting can be switched off per block.
	 */
	public function test_badge_is_absent_when_highlighting_is_off(): void {
		$this->assertStringNotContainsString(
			'oa-best-badge',
			$this->render( array( 'highlightBest' => false ) )
		);
	}

	/**
	 * The event header carries a machine-readable kickoff time.
	 */
	public function test_event_header_carries_the_kickoff(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'class="oa-event"', $html );
		$this->assertMatchesRegularExpression(
			'#<time class="oa-event__kickoff" datetime="\d{4}-\d{2}-\d{2}T#',
			$html
		);
	}

	/**
	 * Column headings are buttons the reader can sort by.
	 *
	 * The aria-sort attribute is what assistive technology reads, so it has to
	 * be on the cell from the first render rather than appearing only once
	 * JavaScript has run.
	 */
	public function test_headings_are_sortable(): void {
		$html = $this->render();

		$this->assertStringContainsString( '<th scope="col" class="oa-sortable" aria-sort="none">', $html );
		$this->assertStringContainsString( 'class="oa-sort" data-column="0"', $html );

		// One heading per outcome, plus the bookmaker column.
		$this->assertSame( 4, substr_count( $html, 'class="oa-sort"' ) );
	}

	/**
	 * Rows remember the order the editor arranged them in.
	 *
	 * Sorting is a reader's temporary override, so the configured order has to
	 * survive it.
	 */
	public function test_rows_record_their_configured_order(): void {
		$html = $this->render();

		foreach ( range( 0, count( self::SHOWN ) - 1 ) as $position ) {
			$this->assertStringContainsString( sprintf( '<tr data-order="%d">', $position ), $html );
		}
	}

	/**
	 * Price cells carry their column heading for the small-screen layout.
	 */
	public function test_price_cells_carry_a_label_for_narrow_screens(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'data-label="Home"', $html );
		$this->assertStringContainsString( 'data-label="Draw"', $html );
		$this->assertStringContainsString( 'data-label="Away"', $html );
	}

	/**
	 * Decimal values found in the markup.
	 *
	 * @param string $html Rendered markup.
	 * @return string[]
	 */
	private function decimals( string $html ): array {
		preg_match_all( '/data-decimal="([\d.]+)"/', $html, $matches );

		return $matches[1];
	}

	/**
	 * Monogram hues found in the markup.
	 *
	 * @param string $html Rendered markup.
	 * @return string[]
	 */
	private function hues( string $html ): array {
		preg_match_all( '/--oa-monogram-hue:(\d+)/', $html, $matches );

		return $matches[1];
	}
}
