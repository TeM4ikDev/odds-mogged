<?php
/**
 * Catalogue of known bookmakers.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Domain;

/**
 * Registry of the bookmakers the plugin knows about.
 *
 * Ships with UK and US defaults and is filterable, so a site can add or rename a
 * bookmaker without forking the plugin. Each default carries a homepage URL;
 * administrator-supplied outbound URLs replace it.
 */
final class BookmakerRegistry {

	/**
	 * Default catalogue: id => [ display name, default outbound URL ].
	 *
	 * The URL is only a starting point; administrators can replace it with
	 * their own (e.g. affiliate) link on the settings screen.
	 *
	 * @var array<string,array{0:string,1:string}>
	 */
	private const DEFAULTS = array(
		'bet365'      => array( 'bet365', 'https://www.bet365.com' ),
		'williamhill' => array( 'William Hill', 'https://www.williamhill.com' ),
		'paddypower'  => array( 'Paddy Power', 'https://www.paddypower.com' ),
		'betfair'     => array( 'Betfair', 'https://www.betfair.com' ),
		'ladbrokes'   => array( 'Ladbrokes', 'https://www.ladbrokes.com' ),
		'coral'       => array( 'Coral', 'https://www.coral.co.uk' ),
		'skybet'      => array( 'Sky Bet', 'https://www.skybet.com' ),
		'unibet'      => array( 'Unibet', 'https://www.unibet.co.uk' ),
		'betvictor'   => array( 'BetVictor', 'https://www.betvictor.com' ),
		'betway'      => array( 'Betway', 'https://www.betway.com' ),
		// US sportsbooks, as quoted by the Oddschecker US pages.
		'fanduel'     => array( 'FanDuel', 'https://sportsbook.fanduel.com' ),
		'draftkings'  => array( 'DraftKings', 'https://sportsbook.draftkings.com' ),
		'betmgm'      => array( 'BetMGM', 'https://sports.betmgm.com' ),
		'caesars'     => array( 'Caesars', 'https://www.caesars.com/sportsbook-and-casino' ),
		'fanatics'    => array( 'Fanatics', 'https://sportsbook.fanatics.com' ),
		'betrivers'   => array( 'BetRivers', 'https://www.betrivers.com' ),
		'borgata'     => array( 'Borgata', 'https://sports.borgataonline.com' ),
		// Offshore books The Odds API returns for the us region.
		'betonlineag' => array( 'BetOnline.ag', 'https://www.betonline.ag' ),
		'betus'       => array( 'BetUS', 'https://www.betus.com.pa' ),
		'bovada'      => array( 'Bovada', 'https://www.bovada.lv' ),
		'lowvigag'    => array( 'LowVig.ag', 'https://www.lowvig.ag' ),
		'mybookieag'  => array( 'MyBookie.ag', 'https://www.mybookie.ag' ),
	);

	/**
	 * Resolved bookmakers, keyed by id.
	 *
	 * @var array<string,Bookmaker>|null
	 */
	private ?array $bookmakers = null;

	/**
	 * Constructor.
	 *
	 * @param array<string,string> $url_overrides  Bookmaker id => outbound URL, from settings.
	 * @param array<string,string> $logo_overrides Bookmaker id => logo URL, from settings.
	 */
	public function __construct(
		private readonly array $url_overrides = array(),
		private readonly array $logo_overrides = array()
	) {}

	/**
	 * All known bookmakers, keyed by id.
	 *
	 * @return array<string,Bookmaker>
	 */
	public function all(): array {
		if ( null !== $this->bookmakers ) {
			return $this->bookmakers;
		}

		$bookmakers = array();

		foreach ( self::DEFAULTS as $id => [ $name, $default_url ] ) {
			// A saved empty string counts as "not set", so the default applies.
			$override = trim( (string) ( $this->url_overrides[ $id ] ?? '' ) );

			// Logos ship with no default: the plugin bundles no third-party
			// trademarks, and an unset logo renders as a monogram instead.
			$logo = trim( (string) ( $this->logo_overrides[ $id ] ?? '' ) );

			$bookmakers[ $id ] = new Bookmaker(
				$id,
				$name,
				'' !== $override ? $override : $default_url,
				$logo
			);
		}

		/**
		 * Filters the bookmaker catalogue.
		 *
		 * @param array<string,Bookmaker> $bookmakers    Bookmakers keyed by id.
		 * @param array<string,string>    $url_overrides Administrator URL overrides.
		 */
		$filtered = apply_filters( 'odds_aggregator_bookmakers', $bookmakers, $this->url_overrides );

		$this->bookmakers = array_filter(
			is_array( $filtered ) ? $filtered : $bookmakers,
			static fn( $bookmaker ): bool => $bookmaker instanceof Bookmaker
		);

		return $this->bookmakers;
	}

	/**
	 * One bookmaker by id, or null when unknown.
	 *
	 * @param string $id Bookmaker id.
	 * @return Bookmaker|null
	 */
	public function get( string $id ): ?Bookmaker {
		return $this->all()[ $id ] ?? null;
	}

	/**
	 * Subset of the catalogue, in the order requested.
	 *
	 * Unknown ids are skipped rather than throwing, so a block that references
	 * a bookmaker which has since been removed still renders.
	 *
	 * @param string[] $ids Bookmaker ids.
	 * @return array<string,Bookmaker>
	 */
	public function only( array $ids ): array {
		$subset = array();

		foreach ( $ids as $id ) {
			$bookmaker = $this->get( (string) $id );

			if ( $bookmaker instanceof Bookmaker ) {
				$subset[ $bookmaker->id() ] = $bookmaker;
			}
		}

		return $subset;
	}

	/**
	 * All known bookmaker ids.
	 *
	 * @return string[]
	 */
	public function ids(): array {
		return array_keys( $this->all() );
	}
}
