<?php
/**
 * Admin settings screen.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Admin;

use OddsAggregator\Domain\Bookmaker;
use OddsAggregator\Domain\BookmakerRegistry;
use OddsAggregator\Domain\Market;
use OddsAggregator\Domain\MarketRegistry;
use OddsAggregator\Odds\OddsRepository;
use OddsAggregator\Providers\OddsApiProvider;
use OddsAggregator\Providers\ProviderException;
use OddsAggregator\Providers\ProviderFactory;

/**
 * Top-level "Odds Aggregator" admin screen, split into tabs.
 *
 * Built on the Settings API rather than a hand-rolled form, so nonces,
 * capability checks and the options write are handled by core. Each tab is a
 * separate Settings API page registered against the same option, and posts
 * only its own fields - {@see Settings::sanitize()} merges them over the
 * stored value so saving one tab never clears another.
 */
final class SettingsPage {

	/**
	 * Settings group name.
	 *
	 * @var string
	 */
	private const GROUP = 'odds_aggregator_settings_group';

	/**
	 * Menu and page slug.
	 *
	 * @var string
	 */
	public const SLUG = 'odds-aggregator';

	/**
	 * Constructor.
	 *
	 * @param Settings          $settings   Plugin settings.
	 * @param ProviderFactory   $factory    Provider construction.
	 * @param BookmakerRegistry $bookmakers Bookmaker catalogue.
	 * @param MarketRegistry    $markets    Market catalogue.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly ProviderFactory $factory,
		private readonly BookmakerRegistry $bookmakers,
		private readonly MarketRegistry $markets
	) {}

	/**
	 * Tab slugs mapped to their titles.
	 *
	 * @return array<string,string>
	 */
	public static function tabs(): array {
		return array(
			'general'     => __( 'General', 'odds-aggregator' ),
			'bookmakers'  => __( 'Bookmakers', 'odds-aggregator' ),
			'markets'     => __( 'Markets', 'odds-aggregator' ),
			'performance' => __( 'Performance', 'odds-aggregator' ),
		);
	}

	/**
	 * Admin URL for a tab.
	 *
	 * @param string $tab Tab slug.
	 * @return string
	 */
	public static function url( string $tab = 'general' ): string {
		return admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $tab );
	}

	/**
	 * Registers the menu entry and the settings fields.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_notices', array( $this, 'render_provider_error' ) );
		add_action( 'admin_post_odds_aggregator_test_source', array( $this, 'handle_test_source' ) );
		add_filter( 'pre_update_option_' . Settings::OPTION, array( $this, 'guard_provider_change' ), 10, 2 );
	}

	/**
	 * Adds the top-level menu entry.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_menu_page(
			__( 'Odds Aggregator', 'odds-aggregator' ),
			__( 'Odds Aggregator', 'odds-aggregator' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render_page' ),
			'dashicons-chart-line',
			81
		);
	}

	/**
	 * The tab currently being viewed.
	 *
	 * @return string
	 */
	private function current_tab(): string {
		/*
		 * Which tab to display is navigation state from a plain link, not
		 * submitted data: it changes nothing and is validated against the
		 * known tabs below. The save itself posts to options.php, which core
		 * nonce-checks.
		 */
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$requested = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : '';

		return array_key_exists( $requested, self::tabs() ) ? $requested : 'general';
	}

	/**
	 * Settings API page slug for a tab.
	 *
	 * @param string $tab Tab slug.
	 * @return string
	 */
	private function page_slug( string $tab ): string {
		return self::SLUG . '-' . $tab;
	}

	/**
	 * Registers the option, and one section per tab.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this->settings, 'sanitize' ),
				'default'           => Settings::defaults(),
			)
		);

		// --- General -------------------------------------------------------
		add_settings_section(
			'odds_aggregator_status',
			__( 'Status', 'odds-aggregator' ),
			array( $this, 'render_status' ),
			$this->page_slug( 'general' )
		);

		add_settings_section(
			'odds_aggregator_source',
			__( 'Data source', 'odds-aggregator' ),
			array( $this, 'render_source_intro' ),
			$this->page_slug( 'general' )
		);

		add_settings_field(
			'provider',
			__( 'Provider', 'odds-aggregator' ),
			array( $this, 'render_provider_field' ),
			$this->page_slug( 'general' ),
			'odds_aggregator_source'
		);

		add_settings_field(
			'api_key',
			__( 'API key', 'odds-aggregator' ),
			array( $this, 'render_api_key_field' ),
			$this->page_slug( 'general' ),
			'odds_aggregator_source'
		);

		add_settings_field(
			'oddsapi_regions',
			__( 'Bookmaker region', 'odds-aggregator' ),
			array( $this, 'render_regions_field' ),
			$this->page_slug( 'general' ),
			'odds_aggregator_source'
		);

		add_settings_section(
			'odds_aggregator_display',
			__( 'Display', 'odds-aggregator' ),
			'__return_false',
			$this->page_slug( 'general' )
		);

		add_settings_field(
			'default_format',
			__( 'Default odds format', 'odds-aggregator' ),
			array( $this, 'render_format_field' ),
			$this->page_slug( 'general' ),
			'odds_aggregator_display'
		);

		// --- Bookmakers ----------------------------------------------------
		add_settings_section(
			'odds_aggregator_bookmakers',
			__( 'Bookmakers', 'odds-aggregator' ),
			array( $this, 'render_bookmakers_intro' ),
			$this->page_slug( 'bookmakers' )
		);

		add_settings_field(
			'bookmakers',
			__( 'Available bookmakers', 'odds-aggregator' ),
			array( $this, 'render_bookmaker_fields' ),
			$this->page_slug( 'bookmakers' ),
			'odds_aggregator_bookmakers'
		);

		// --- Markets -------------------------------------------------------
		add_settings_section(
			'odds_aggregator_markets',
			__( 'Markets', 'odds-aggregator' ),
			array( $this, 'render_markets_intro' ),
			$this->page_slug( 'markets' )
		);

		add_settings_field(
			'markets',
			__( 'Enabled markets', 'odds-aggregator' ),
			array( $this, 'render_market_fields' ),
			$this->page_slug( 'markets' ),
			'odds_aggregator_markets'
		);

		// --- Performance ---------------------------------------------------
		add_settings_section(
			'odds_aggregator_performance',
			__( 'Caching and rate limiting', 'odds-aggregator' ),
			array( $this, 'render_performance_intro' ),
			$this->page_slug( 'performance' )
		);

		add_settings_field(
			'performance',
			__( 'Timings', 'odds-aggregator' ),
			array( $this, 'render_performance_fields' ),
			$this->page_slug( 'performance' ),
			'odds_aggregator_performance'
		);
	}

	/**
	 * Renders the page shell and the tab navigation.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$current = $this->current_tab();

		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Odds Aggregator', 'odds-aggregator' ); ?></h1>

			<?php
			/*
			 * Core only prints these automatically on its own options screens
			 * (via options-head.php). This is a top-level admin.php page, so
			 * without this call every save message is written to a 30-second
			 * transient and silently expires unseen.
			 */
			settings_errors( Settings::OPTION );
			?>

			<nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php echo esc_attr__( 'Settings sections', 'odds-aggregator' ); ?>">
				<?php foreach ( self::tabs() as $slug => $label ) : ?>
					<a
						href="<?php echo esc_url( self::url( $slug ) ); ?>"
						class="nav-tab<?php echo $slug === $current ? ' nav-tab-active' : ''; ?>"
						<?php echo $slug === $current ? 'aria-current="page"' : ''; ?>
					>
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<form action="options.php" method="post">
				<?php
				settings_fields( self::GROUP );
				?>
				<input
					type="hidden"
					name="<?php echo esc_attr( Settings::OPTION ); ?>[_tab]"
					value="<?php echo esc_attr( $current ); ?>"
				/>
				<?php
				do_settings_sections( $this->page_slug( $current ) );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Refuses to switch to a data source that cannot currently deliver odds.
	 *
	 * Selecting a dead source used to be silently destructive: the event list
	 * emptied and every odds table blanked, with the only clue a notice on a
	 * later page load. The source is therefore probed once, and a failing one
	 * is rejected with an explanation while the working source stays in place.
	 *
	 * @param mixed $value     Settings about to be written.
	 * @param mixed $old_value Settings currently stored.
	 * @return mixed
	 */
	public function guard_provider_change( $value, $old_value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$old      = is_array( $old_value ) ? $old_value : array();
		$previous = (string) ( $old['provider'] ?? 'mock' );
		$chosen   = (string) ( $value['provider'] ?? $previous );
		$switched = $chosen !== $previous;

		/*
		 * A new key or a new region changes what the source would return, so it
		 * earns one probe even though the source itself did not change - that is
		 * the only way to tell an administrator their key works. Every other edit
		 * on the tab still pays for nothing.
		 */
		$recredentialed = ! $switched
			&& 'oddsapi' === $chosen
			&& (
				(string) ( $old['api_key'] ?? '' ) !== (string) ( $value['api_key'] ?? '' )
				|| (string) ( $old['oddsapi_regions'] ?? '' ) !== (string) ( $value['oddsapi_regions'] ?? '' )
			);

		if ( ! $switched && ! $recredentialed ) {
			return $value;
		}

		$probe = $this->probe_provider( $chosen, $value );

		if ( null === $probe['failure'] ) {
			if ( null !== $probe['warning'] ) {
				add_settings_error(
					Settings::OPTION,
					'odds_aggregator_provider_empty',
					sprintf(
						/* translators: 1: provider name, 2: detail. */
						__( 'Saved %1$s, but %2$s', 'odds-aggregator' ),
						$this->provider_label( $chosen ),
						$probe['warning']
					),
					'warning'
				);

				return $value;
			}

			add_settings_error(
				Settings::OPTION,
				'odds_aggregator_provider_ok',
				$switched
					? sprintf(
						/* translators: %s: provider name. */
						__( 'Data source switched to %s.', 'odds-aggregator' ),
						$this->provider_label( $chosen )
					)
					: sprintf(
						/* translators: %s: provider name. */
						__( '%s accepted the new settings.', 'odds-aggregator' ),
						$this->provider_label( $chosen )
					),
				'updated'
			);

			return $value;
		}

		if ( $switched ) {
			add_settings_error(
				Settings::OPTION,
				'odds_aggregator_provider_failed',
				sprintf(
					/* translators: 1: attempted provider name, 2: failure detail, 3: provider left in place. */
					__( 'Could not switch to %1$s: %2$s The data source has been left on %3$s so your odds keep working.', 'odds-aggregator' ),
					$this->provider_label( $chosen ),
					$probe['failure'],
					$this->provider_label( $previous )
				),
				'error'
			);

			$value['provider'] = $previous;

			return $value;
		}

		add_settings_error(
			Settings::OPTION,
			'odds_aggregator_credentials_failed',
			sprintf(
				/* translators: 1: provider name, 2: failure detail. */
				__( '%1$s rejected the new settings: %2$s Your previous API key and region have been kept.', 'odds-aggregator' ),
				$this->provider_label( $chosen ),
				$probe['failure']
			),
			'error'
		);

		// The source stays selected, so leaving broken credentials in place would
		// blank the front end with nothing on screen to explain it.
		$value['api_key']         = (string) ( $old['api_key'] ?? '' );
		$value['oddsapi_regions'] = (string) ( $old['oddsapi_regions'] ?? 'us' );

		return $value;
	}

	/**
	 * Tries a provider once.
	 *
	 * @param string              $id      Provider id.
	 * @param array<string,mixed> $pending Settings being saved, if any.
	 * @return array{failure:?string,warning:?string}
	 */
	private function probe_provider( string $id, array $pending = array() ): array {
		$factory = array() === $pending
			? $this->factory
			: $this->factory->for_settings( $this->settings->with_overrides( $pending ) );

		try {
			$provider = $factory->create( $id );

			if ( ! $provider->is_available() ) {
				return array(
					'failure' => __( 'it is not configured yet (an API key is required).', 'odds-aggregator' ),
					'warning' => null,
				);
			}

			if ( array() === $provider->fetch_events() ) {
				// A licensed feed returns nothing between fixtures, so an empty list
				// is not a reason to reject a key that answered at all.
				return array(
					'failure' => null,
					'warning' => __( 'it has no events to quote at the moment.', 'odds-aggregator' ),
				);
			}
		} catch ( ProviderException $exception ) {
			return array(
				'failure' => $exception->getMessage(),
				'warning' => null,
			);
		}

		return array(
			'failure' => null,
			'warning' => null,
		);
	}

	/**
	 * Human readable name for a provider id.
	 *
	 * @param string $id Provider id.
	 * @return string
	 */
	private function provider_label( string $id ): string {
		return (string) ( $this->factory->choices()[ $id ] ?? $id );
	}

	/**
	 * Warns administrators when the active data source is failing.
	 *
	 * @return void
	 */
	public function render_provider_error(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$error = OddsRepository::last_error();

		if ( null === $error ) {
			return;
		}

		?>
		<div class="notice notice-error">
			<p>
				<strong><?php echo esc_html__( 'Odds Aggregator: the selected data source is not returning odds.', 'odds-aggregator' ); ?></strong>
			</p>
			<p><code><?php echo esc_html( $error['message'] ); ?></code></p>
			<p>
				<?php
				printf(
					/* translators: %s: link to the plugin settings screen. */
					esc_html__( 'Until this is resolved there will be no events to choose from in the editor and odds tables will render empty. Switch the provider back to "Sample data (offline)" on the %s to carry on working.', 'odds-aggregator' ),
					'<a href="' . esc_url( self::url( 'general' ) ) . '">'
						. esc_html__( 'General tab', 'odds-aggregator' ) . '</a>'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Shows whether the configured source is actually delivering.
	 *
	 * The screen used to speak up only when something had already broken. This
	 * reports the healthy case too, which is what an administrator checks for.
	 *
	 * @return void
	 */
	public function render_status(): void {
		$error     = OddsRepository::last_error();
		$allowance = get_transient( OddsApiProvider::ALLOWANCE_KEY );
		?>
		<table class="widefat striped" style="max-width:48rem">
			<tbody>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Active source', 'odds-aggregator' ); ?></th>
					<td><?php echo esc_html( $this->provider_label( $this->settings->provider() ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Last fetch', 'odds-aggregator' ); ?></th>
					<td>
						<?php if ( null === $error ) : ?>
							<?php echo esc_html__( 'No errors reported.', 'odds-aggregator' ); ?>
						<?php else : ?>
							<code><?php echo esc_html( $error['message'] ); ?></code>
						<?php endif; ?>
					</td>
				</tr>
				<?php if ( is_array( $allowance ) ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html__( 'API credits left', 'odds-aggregator' ); ?></th>
						<td>
							<?php
							printf(
								/* translators: 1: credits remaining, 2: credits used, 3: human readable age. */
								esc_html__( '%1$d remaining, %2$d used. Reported %3$s ago.', 'odds-aggregator' ),
								(int) ( $allowance['remaining'] ?? 0 ),
								(int) ( $allowance['used'] ?? 0 ),
								esc_html( human_time_diff( (int) ( $allowance['checked'] ?? time() ), time() ) )
							);
							?>
						</td>
					</tr>
				<?php endif; ?>
			</tbody>
		</table>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="odds_aggregator_test_source" />
			<?php wp_nonce_field( 'odds_aggregator_test_source' ); ?>
			<?php submit_button( __( 'Test connection', 'odds-aggregator' ), 'secondary', 'submit', false ); ?>
			<span class="description">
				<?php echo esc_html__( 'Fetches once from the configured source. On a metered feed this spends credits.', 'odds-aggregator' ); ?>
			</span>
		</form>
		<?php
	}

	/**
	 * Probes the configured source on demand and reports back.
	 *
	 * @return void
	 */
	public function handle_test_source(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'odds-aggregator' ) );
		}

		check_admin_referer( 'odds_aggregator_test_source' );

		$provider = $this->settings->provider();
		$label    = $this->provider_label( $provider );
		$probe    = $this->probe_provider( $provider );

		if ( null !== $probe['failure'] ) {
			add_settings_error(
				Settings::OPTION,
				'odds_aggregator_test_failed',
				sprintf(
					/* translators: 1: provider name, 2: failure detail. */
					__( '%1$s is not answering: %2$s', 'odds-aggregator' ),
					$label,
					$probe['failure']
				),
				'error'
			);
		} elseif ( null !== $probe['warning'] ) {
			add_settings_error(
				Settings::OPTION,
				'odds_aggregator_test_empty',
				sprintf(
					/* translators: 1: provider name, 2: detail. */
					__( '%1$s answered, but %2$s', 'odds-aggregator' ),
					$label,
					$probe['warning']
				),
				'warning'
			);
		} else {
			add_settings_error(
				Settings::OPTION,
				'odds_aggregator_test_ok',
				sprintf(
					/* translators: %s: provider name. */
					__( '%s answered with events.', 'odds-aggregator' ),
					$label
				),
				'updated'
			);
		}

		// Core only persists notices across a redirect from its own screens.
		set_transient( 'settings_errors', get_settings_errors(), 30 );

		wp_safe_redirect( add_query_arg( 'settings-updated', 'true', self::url( 'general' ) ) );

		exit;
	}

	/**
	 * Explains the provider choice.
	 *
	 * @return void
	 */
	public function render_source_intro(): void {
		?>
		<p>
			<?php
			echo esc_html__(
				'The Odds API is the supported live source; it needs a free API key from the-odds-api.com. Sample data works offline and needs no configuration. The Oddschecker scraper is kept for reference only - the site is behind Cloudflare and answers with 403, and its terms do not permit scraping.',
				'odds-aggregator'
			);
			?>
		</p>
		<p>
			<?php
			echo esc_html__(
				'A source is tested when you save, and so is a new API key or region. If it cannot return odds the change is rejected and your working configuration is kept.',
				'odds-aggregator'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Explains bookmaker links.
	 *
	 * @return void
	 */
	public function render_bookmakers_intro(): void {
		?>
		<p><?php echo esc_html__( 'Choose which bookmakers are available site-wide, and set the outbound link used for each. Individual blocks can show fewer, but never more.', 'odds-aggregator' ); ?></p>
		<?php
	}

	/**
	 * Explains market selection.
	 *
	 * @return void
	 */
	public function render_markets_intro(): void {
		?>
		<p><?php echo esc_html__( 'Markets available to blocks. Individual blocks can narrow this further.', 'odds-aggregator' ); ?></p>
		<?php
	}

	/**
	 * Explains the caching model.
	 *
	 * @return void
	 */
	public function render_performance_intro(): void {
		?>
		<p>
			<?php
			echo esc_html__(
				'Odds older than the fresh window are still shown while a background refresh runs, so visitors never wait on a fetch. Lower the fresh window for livelier prices, raise it to reduce outbound traffic.',
				'odds-aggregator'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Provider dropdown.
	 *
	 * @return void
	 */
	public function render_provider_field(): void {
		$current = $this->settings->provider();
		?>
		<select name="<?php echo esc_attr( Settings::OPTION ); ?>[provider]">
			<?php foreach ( $this->factory->choices() as $id => $label ) : ?>
				<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $current, $id ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * API key input.
	 *
	 * @return void
	 */
	public function render_api_key_field(): void {
		?>
		<input
			type="password"
			class="regular-text"
			autocomplete="off"
			name="<?php echo esc_attr( Settings::OPTION ); ?>[api_key]"
			value="<?php echo esc_attr( $this->settings->api_key() ); ?>"
		/>
		<p class="description"><?php echo esc_html__( 'Only needed by providers that require one, such as The Odds API.', 'odds-aggregator' ); ?></p>
		<?php
	}

	/**
	 * Bookmaker region dropdown for The Odds API.
	 *
	 * @return void
	 */
	public function render_regions_field(): void {
		$current = $this->settings->oddsapi_regions();
		$regions = array(
			'us'  => __( 'United States', 'odds-aggregator' ),
			'us2' => __( 'United States (additional books)', 'odds-aggregator' ),
			'uk'  => __( 'United Kingdom', 'odds-aggregator' ),
			'eu'  => __( 'Europe', 'odds-aggregator' ),
			'au'  => __( 'Australia', 'odds-aggregator' ),
		);
		?>
		<select name="<?php echo esc_attr( Settings::OPTION ); ?>[oddsapi_regions]">
			<?php foreach ( $regions as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<?php
			echo esc_html__(
				'Used by The Odds API only. Each refresh costs one credit per market per region, so picking a second region doubles what every refresh spends.',
				'odds-aggregator'
			);
			?>
		</p>
		<p class="description">
			<?php
			echo esc_html__(
				'On the United States region this plugin shows DraftKings, FanDuel, BetMGM, BetRivers, BetOnline.ag, BetUS, Bovada, LowVig.ag and MyBookie.ag. Caesars and Fanatics need a paid plan, and bet365 and Borgata are not offered at all, so those rows stay empty.',
				'odds-aggregator'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Cache and rate-limit inputs.
	 *
	 * @return void
	 */
	public function render_performance_fields(): void {
		$option = esc_attr( Settings::OPTION );
		?>
		<p>
			<label>
				<?php echo esc_html__( 'Fresh for (seconds)', 'odds-aggregator' ); ?>
				<input type="number" min="10" max="3600" name="<?php echo $option; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[cache_ttl]" value="<?php echo esc_attr( (string) $this->settings->cache_ttl() ); ?>" />
			</label>
		</p>
		<p>
			<label>
				<?php echo esc_html__( 'Serve stale for up to (seconds)', 'odds-aggregator' ); ?>
				<input type="number" min="10" max="86400" name="<?php echo $option; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[stale_ttl]" value="<?php echo esc_attr( (string) $this->settings->stale_ttl() ); ?>" />
			</label>
		</p>
		<p>
			<label>
				<?php echo esc_html__( 'The Odds API: seconds between upstream fetches', 'odds-aggregator' ); ?>
				<input type="number" min="600" max="86400" name="<?php echo $option; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[oddsapi_ttl]" value="<?php echo esc_attr( (string) $this->settings->oddsapi_ttl() ); ?>" />
			</label>
			<span class="description">
				<?php
				echo esc_html__(
					'One fetch serves every event. At 10800 (three hours) two markets in one region cost about 480 credits a month, which fits the free plan allowance of 500. Lower it only on a paid plan.',
					'odds-aggregator'
				);
				?>
			</span>
		</p>
		<p>
			<label>
				<?php echo esc_html__( 'Requests per minute', 'odds-aggregator' ); ?>
				<input type="number" min="1" max="600" name="<?php echo $option; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[rate_limit]" value="<?php echo esc_attr( (string) $this->settings->rate_limit() ); ?>" />
			</label>
		</p>
		<?php
	}

	/**
	 * Bookmaker checkboxes and link inputs.
	 *
	 * @return void
	 */
	public function render_bookmaker_fields(): void {
		$enabled = $this->settings->bookmakers();
		$option  = esc_attr( Settings::OPTION );
		?>
		<table class="widefat striped" style="max-width:48rem">
			<thead>
				<tr>
					<th scope="col"><?php echo esc_html__( 'Show', 'odds-aggregator' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Bookmaker', 'odds-aggregator' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Outbound link', 'odds-aggregator' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Logo URL', 'odds-aggregator' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $this->bookmakers->all() as $bookmaker ) : ?>
				<?php
				if ( ! $bookmaker instanceof Bookmaker ) {
					continue;
				}
				$id = $bookmaker->id();
				?>
				<tr>
					<td>
						<input
							type="checkbox"
							name="<?php echo $option; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[bookmakers][]"
							value="<?php echo esc_attr( $id ); ?>"
							<?php checked( in_array( $id, $enabled, true ) ); ?>
						/>
					</td>
					<th scope="row"><?php echo esc_html( $bookmaker->name() ); ?></th>
					<td>
						<input
							type="url"
							class="regular-text"
							placeholder="https://"
							name="<?php echo $option; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[bookmaker_urls][<?php echo esc_attr( $id ); ?>]"
							value="<?php echo esc_attr( $bookmaker->url() ); ?>"
						/>
					</td>
					<td>
						<input
							type="url"
							class="regular-text"
							placeholder="https://"
							name="<?php echo $option; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[bookmaker_logos][<?php echo esc_attr( $id ); ?>]"
							value="<?php echo esc_attr( $bookmaker->logo() ); ?>"
						/>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Market checkboxes.
	 *
	 * @return void
	 */
	public function render_market_fields(): void {
		$enabled = $this->settings->markets();
		$option  = esc_attr( Settings::OPTION );

		foreach ( $this->markets->all() as $market ) {
			if ( ! $market instanceof Market ) {
				continue;
			}
			?>
			<p>
				<label>
					<input
						type="checkbox"
						name="<?php echo $option; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[markets][]"
						value="<?php echo esc_attr( $market->key() ); ?>"
						<?php checked( in_array( $market->key(), $enabled, true ) ); ?>
					/>
					<?php echo esc_html( $market->label() ); ?>
				</label>
			</p>
			<?php
		}
	}

	/**
	 * Default display format dropdown.
	 *
	 * @return void
	 */
	public function render_format_field(): void {
		$current = $this->settings->default_format();
		$formats = array(
			'decimal'    => __( 'Decimal', 'odds-aggregator' ),
			'fractional' => __( 'Fractional', 'odds-aggregator' ),
			'american'   => __( 'American', 'odds-aggregator' ),
		);
		?>
		<select name="<?php echo esc_attr( Settings::OPTION ); ?>[default_format]">
			<?php foreach ( $formats as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description"><?php echo esc_html__( 'The format a page opens in. Readers can switch it themselves.', 'odds-aggregator' ); ?></p>
		<?php
	}
}
