<?php
/**
 * Scheduled cache warming.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Cache;

use OddsAggregator\Admin\Settings;
use OddsAggregator\Domain\Event;
use OddsAggregator\Odds\OddsRepository;

/**
 * Keeps the cache warm from WP-Cron instead of from visitor requests.
 *
 * This is what turns the cache from "the first unlucky visitor waits for a
 * scrape" into "nobody ever waits". It also concentrates outbound traffic into
 * a predictable trickle, which is far easier to keep inside a rate limit than
 * demand-driven fetching.
 */
final class CacheWarmer {

	/**
	 * Cron hook.
	 *
	 * @var string
	 */
	public const HOOK = 'odds_aggregator_warm_cache';

	/**
	 * Custom schedule name.
	 *
	 * @var string
	 */
	public const SCHEDULE = 'odds_aggregator_five_minutes';

	/**
	 * Events refreshed per run, to bound a single cron tick.
	 *
	 * @var int
	 */
	private const BATCH_SIZE = 6;

	/**
	 * Constructor.
	 *
	 * @param OddsRepository $repository Odds access.
	 * @param Settings       $settings   Plugin settings.
	 */
	public function __construct(
		private readonly OddsRepository $repository,
		private readonly Settings $settings
	) {}

	/**
	 * Registers the schedule and the handlers.
	 *
	 * @return void
	 */
	public function register(): void {
		// phpcs:ignore WordPress.WP.CronInterval.ChangeDetected, WordPress.WP.CronInterval.CronSchedulesInterval -- see add_schedule().
		add_filter( 'cron_schedules', array( self::class, 'add_schedule' ) );
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( OddsRepository::REFRESH_HOOK, array( $this, 'refresh_event' ), 10, 2 );
	}

	/**
	 * Adds the five-minute interval.
	 *
	 * @param mixed $schedules Registered schedules.
	 * @return array<string,array<string,mixed>>
	 */
	public static function add_schedule( $schedules ): array {
		$schedules = is_array( $schedules ) ? $schedules : array();

		/*
		 * Five minutes is deliberately below the recommended fifteen: odds move
		 * quickly, and the whole point of the warmer is that visitors read a
		 * cache that is never far behind. Outbound volume stays bounded by the
		 * rate limiter and the batch size regardless of how often this fires.
		 */
		$schedules[ self::SCHEDULE ] = array(
			// phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every five minutes (Odds Aggregator)', 'odds-aggregator' ),
		);

		return $schedules;
	}

	/**
	 * Refreshes a batch of events.
	 *
	 * @return void
	 */
	public function run(): void {
		$markets = $this->settings->markets();

		if ( array() === $markets ) {
			return;
		}

		$events = array_slice( $this->repository->events(), 0, self::BATCH_SIZE );

		foreach ( $events as $event ) {
			if ( $event instanceof Event ) {
				$this->repository->refresh( $event->id(), $markets );
			}
		}
	}

	/**
	 * Refreshes one event, as queued by a stale read.
	 *
	 * @param string $event_id    Event id.
	 * @param mixed  $market_keys Market keys.
	 * @return void
	 */
	public function refresh_event( string $event_id, $market_keys ): void {
		$markets = is_array( $market_keys ) ? array_map( 'strval', $market_keys ) : $this->settings->markets();

		if ( array() === $markets ) {
			return;
		}

		$this->repository->refresh( $event_id, $markets );
	}

	/**
	 * Schedules the recurring job. Called on activation.
	 *
	 * @return void
	 */
	public static function schedule(): void {
		/*
		 * Activation runs after plugins_loaded, so boot() has not registered the
		 * cron_schedules filter yet and wp_schedule_event() would reject our
		 * interval as unknown. Register it here too; add_filter is idempotent
		 * for an identical callback, so the normal request path is unaffected.
		 */
		// phpcs:ignore WordPress.WP.CronInterval.ChangeDetected, WordPress.WP.CronInterval.CronSchedulesInterval -- see add_schedule().
		add_filter( 'cron_schedules', array( self::class, 'add_schedule' ) );

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::SCHEDULE, self::HOOK );
		}
	}

	/**
	 * Clears scheduled jobs. Called on deactivation.
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( OddsRepository::REFRESH_HOOK );
	}
}
