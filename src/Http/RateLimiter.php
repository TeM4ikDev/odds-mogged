<?php
/**
 * Outbound request throttling.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Http;

/**
 * Token bucket, persisted so the limit holds across PHP requests.
 *
 * Scraping a third-party site from a busy WordPress install is the quickest
 * way to get an IP banned. A bucket lets short bursts through (useful when the
 * cron warmer refreshes several events at once) while capping the sustained
 * rate.
 */
final class RateLimiter {

	/**
	 * Transient key prefix.
	 *
	 * @var string
	 */
	private const PREFIX = 'odds_aggregator_rl_';

	/**
	 * Constructor.
	 *
	 * @param int $requests_per_minute Sustained ceiling.
	 * @param int $burst               Bucket capacity; defaults to the per-minute rate.
	 */
	public function __construct(
		private readonly int $requests_per_minute = 20,
		private readonly int $burst = 0
	) {}

	/**
	 * Consumes a token, returning false when the bucket is empty.
	 *
	 * @param string $bucket Bucket name, normally the provider id.
	 * @return bool
	 */
	public function consume( string $bucket ): bool {
		$capacity = $this->capacity();
		$rate     = $this->requests_per_minute / 60.0;
		$key      = self::PREFIX . $bucket;
		$now      = time();

		$state = get_transient( $key );

		if ( ! is_array( $state ) || ! isset( $state['tokens'], $state['updated'] ) ) {
			$state = array(
				'tokens'  => (float) $capacity,
				'updated' => $now,
			);
		}

		// Refill for the time elapsed since the last request.
		$elapsed          = max( 0, $now - (int) $state['updated'] );
		$state['tokens']  = min( (float) $capacity, (float) $state['tokens'] + ( $elapsed * $rate ) );
		$state['updated'] = $now;

		if ( $state['tokens'] < 1.0 ) {
			$this->persist( $key, $state );

			return false;
		}

		--$state['tokens'];
		$this->persist( $key, $state );

		return true;
	}

	/**
	 * Seconds until another token becomes available.
	 *
	 * @param string $bucket Bucket name.
	 * @return int
	 */
	public function retry_after( string $bucket ): int {
		$state = get_transient( self::PREFIX . $bucket );

		if ( ! is_array( $state ) || ! isset( $state['tokens'] ) ) {
			return 0;
		}

		$deficit = 1.0 - (float) $state['tokens'];

		if ( $deficit <= 0 ) {
			return 0;
		}

		return (int) ceil( $deficit / ( $this->requests_per_minute / 60.0 ) );
	}

	/**
	 * Bucket capacity.
	 *
	 * @return int
	 */
	private function capacity(): int {
		return max( 1, $this->burst > 0 ? $this->burst : $this->requests_per_minute );
	}

	/**
	 * Writes bucket state back, with a TTL comfortably past a full refill.
	 *
	 * @param string              $key   Transient key.
	 * @param array<string,mixed> $state Bucket state.
	 * @return void
	 */
	private function persist( string $key, array $state ): void {
		set_transient( $key, $state, 300 );
	}
}
