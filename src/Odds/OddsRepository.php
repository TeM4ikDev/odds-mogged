<?php
/**
 * Cached access to odds data.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Odds;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use OddsAggregator\Cache\CacheEntry;
use OddsAggregator\Cache\OddsCache;
use OddsAggregator\Domain\Event;
use OddsAggregator\Domain\MarketRegistry;
use OddsAggregator\Domain\OddsLine;
use OddsAggregator\Providers\ProviderException;
use OddsAggregator\Providers\ProviderFactory;

/**
 * The only thing the front end, the block and the REST API read odds through.
 *
 * Implements the read path the performance requirement needs:
 *
 * - fresh cache hit  -> return immediately, no network
 * - stale cache hit  -> return immediately, queue a background refresh
 * - miss             -> fetch once (behind a lock) and store
 *
 * The practical effect is that rendering a page never waits on a scrape, and a
 * burst of traffic against an expired key produces one upstream request rather
 * than one per visitor.
 */
final class OddsRepository {

	/**
	 * Cron hook used to refresh a single event out of band.
	 *
	 * @var string
	 */
	public const REFRESH_HOOK = 'odds_aggregator_refresh_event';

	/**
	 * Transient holding the most recent provider failure.
	 *
	 * @var string
	 */
	public const ERROR_TRANSIENT = 'odds_aggregator_provider_error';

	/**
	 * Constructor.
	 *
	 * @param ProviderFactory $factory  Provider construction.
	 * @param OddsCache       $cache    Cache layer.
	 * @param MarketRegistry  $markets  Market catalogue.
	 */
	public function __construct(
		private readonly ProviderFactory $factory,
		private readonly OddsCache $cache,
		private readonly MarketRegistry $markets
	) {}

	/**
	 * Events available from the active provider.
	 *
	 * @return Event[]
	 */
	public function events(): array {
		$provider = $this->factory->create_or_fallback();
		$key      = OddsCache::key( 'events', $provider->id() );
		$entry    = $this->cache->get( $key );

		if ( $entry instanceof CacheEntry && $entry->is_fresh() ) {
			return $this->hydrate_events( (array) $entry->data() );
		}

		try {
			$events = $provider->fetch_events();
		} catch ( ProviderException $exception ) {
			$this->log( $exception->getMessage() );

			// Stale beats empty: fall back to whatever we last saw.
			return $entry instanceof CacheEntry ? $this->hydrate_events( (array) $entry->data() ) : array();
		}

		$this->cache->set(
			$key,
			array_map( static fn( Event $event ): array => $event->to_array(), $events )
		);

		$this->clear_error();

		return $events;
	}

	/**
	 * One event by id, or null when it is no longer offered.
	 *
	 * @param string $event_id Event id.
	 * @return Event|null
	 */
	public function find_event( string $event_id ): ?Event {
		foreach ( $this->events() as $event ) {
			if ( $event->id() === $event_id ) {
				return $event;
			}
		}

		return null;
	}

	/**
	 * Prices for an event, served from cache wherever possible.
	 *
	 * @param string   $event_id    Event id.
	 * @param string[] $market_keys Market keys to include.
	 * @return OddsLine[]
	 */
	public function odds( string $event_id, array $market_keys ): array {
		$provider = $this->factory->create_or_fallback();
		$key      = $this->odds_key( $provider->id(), $event_id, $market_keys );
		$entry    = $this->cache->get( $key );

		if ( $entry instanceof CacheEntry ) {
			if ( ! $entry->is_fresh() ) {
				$this->queue_refresh( $event_id, $market_keys );
			}

			return $this->hydrate_lines( (array) $entry->data() );
		}

		// Cold cache: one process fetches, the rest render empty and pick it up next time.
		if ( ! $this->cache->acquire_lock( $key ) ) {
			return array();
		}

		try {
			return $this->fetch_and_store( $event_id, $market_keys );
		} finally {
			$this->cache->release_lock( $key );
		}
	}

	/**
	 * Fetches fresh prices and writes them to the cache.
	 *
	 * @param string   $event_id    Event id.
	 * @param string[] $market_keys Market keys to include.
	 * @return OddsLine[]
	 */
	public function refresh( string $event_id, array $market_keys ): array {
		return $this->fetch_and_store( $event_id, $market_keys );
	}

	/**
	 * Performs the upstream fetch, stores the result and notifies listeners.
	 *
	 * @param string   $event_id    Event id.
	 * @param string[] $market_keys Market keys to include.
	 * @return OddsLine[]
	 */
	private function fetch_and_store( string $event_id, array $market_keys ): array {
		$provider = $this->factory->create_or_fallback();
		$event    = $this->find_event( $event_id );

		if ( ! $event instanceof Event ) {
			return array();
		}

		$markets = array_values( $this->markets->only( $market_keys ) );

		if ( array() === $markets ) {
			return array();
		}

		try {
			$lines = $provider->fetch_odds( $event, $markets );
		} catch ( ProviderException $exception ) {
			$this->log( $exception->getMessage() );

			return array();
		}

		$this->cache->set(
			$this->odds_key( $provider->id(), $event_id, $market_keys ),
			array_map( static fn( OddsLine $line ): array => $line->to_array(), $lines )
		);

		$this->clear_error();

		/**
		 * Fires after odds for an event have been refreshed from a provider.
		 *
		 * The plugin's own cache invalidation listens on this; add your own to
		 * push updates elsewhere, write a price history, or send alerts.
		 *
		 * @param OddsLine[] $lines       Freshly fetched rows.
		 * @param string     $event_id    Event the rows belong to.
		 * @param string     $provider_id Provider that produced them.
		 */
		do_action( 'odds_aggregator_odds_updated', $lines, $event_id, $provider->id() );

		return $lines;
	}

	/**
	 * Queues an out-of-band refresh, at most one in flight per key.
	 *
	 * @param string   $event_id    Event id.
	 * @param string[] $market_keys Market keys to include.
	 * @return void
	 */
	private function queue_refresh( string $event_id, array $market_keys ): void {
		$lock = 'refresh_' . OddsCache::key( $event_id, implode( ',', $market_keys ) );

		if ( ! $this->cache->acquire_lock( $lock, 120 ) ) {
			return;
		}

		if ( ! wp_next_scheduled( self::REFRESH_HOOK, array( $event_id, $market_keys ) ) ) {
			wp_schedule_single_event( time(), self::REFRESH_HOOK, array( $event_id, $market_keys ) );
		}
	}

	/**
	 * Cache key for a set of prices.
	 *
	 * Market keys are sorted so that the same request in a different order is
	 * one cache entry, not two.
	 *
	 * @param string   $provider_id Provider id.
	 * @param string   $event_id    Event id.
	 * @param string[] $market_keys Market keys.
	 * @return string
	 */
	private function odds_key( string $provider_id, string $event_id, array $market_keys ): string {
		$markets = array_map( 'strval', $market_keys );
		sort( $markets );

		return OddsCache::key( 'odds', $provider_id, $event_id, implode( ',', $markets ) );
	}

	/**
	 * Rebuilds events from their cached array form.
	 *
	 * @param array<int,mixed> $rows Cached rows.
	 * @return Event[]
	 */
	private function hydrate_events( array $rows ): array {
		$events = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['id'] ) ) {
				continue;
			}

			try {
				$kickoff = new DateTimeImmutable( (string) ( $row['kickoff'] ?? 'now' ), new DateTimeZone( 'UTC' ) );
			} catch ( Exception $exception ) {
				$kickoff = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
			}

			$events[] = new Event(
				(string) $row['id'],
				(string) ( $row['sport'] ?? 'football' ),
				(string) ( $row['league'] ?? '' ),
				(string) ( $row['home'] ?? '' ),
				(string) ( $row['away'] ?? '' ),
				$kickoff
			);
		}

		return $events;
	}

	/**
	 * Rebuilds price rows from their cached array form.
	 *
	 * @param array<int,mixed> $rows Cached rows.
	 * @return OddsLine[]
	 */
	private function hydrate_lines( array $rows ): array {
		$lines = array();

		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$lines[] = OddsLine::from_array( $row );
			}
		}

		return $lines;
	}

	/**
	 * Most recent provider failure, or null when the last fetch succeeded.
	 *
	 * Surfaced in the admin and the block editor so a source that is silently
	 * returning nothing - a blocked scraper, a missing API key - explains
	 * itself instead of leaving an empty screen.
	 *
	 * @return array{provider:string,message:string,time:int}|null
	 */
	public static function last_error(): ?array {
		$error = get_transient( self::ERROR_TRANSIENT );

		if ( ! is_array( $error ) || ! isset( $error['message'] ) ) {
			return null;
		}

		return array(
			'provider' => (string) ( $error['provider'] ?? '' ),
			'message'  => (string) $error['message'],
			'time'     => (int) ( $error['time'] ?? 0 ),
		);
	}

	/**
	 * Records a provider failure for display, and logs it when debugging.
	 *
	 * @param string $message Failure description.
	 * @return void
	 */
	private function log( string $message ): void {
		set_transient(
			self::ERROR_TRANSIENT,
			array(
				'provider' => $this->factory->create_or_fallback()->id(),
				'message'  => $message,
				'time'     => time(),
			),
			HOUR_IN_SECONDS
		);

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[odds-aggregator] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Forgets a recorded failure after a successful fetch.
	 *
	 * @return void
	 */
	private function clear_error(): void {
		delete_transient( self::ERROR_TRANSIENT );
	}
}
