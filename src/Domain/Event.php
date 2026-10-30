<?php
/**
 * A sporting event.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Domain;

use DateTimeImmutable;

/**
 * Immutable event definition.
 */
final class Event {

	/**
	 * Constructor.
	 *
	 * @param string            $id      Machine id, unique per provider.
	 * @param string            $sport   Sport key, e.g. "football".
	 * @param string            $league  League name.
	 * @param string            $home    Home team or competitor.
	 * @param string            $away    Away team or competitor.
	 * @param DateTimeImmutable $kickoff Scheduled start, in UTC.
	 */
	public function __construct(
		private readonly string $id,
		private readonly string $sport,
		private readonly string $league,
		private readonly string $home,
		private readonly string $away,
		private readonly DateTimeImmutable $kickoff
	) {}

	/**
	 * Machine id.
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Sport key.
	 *
	 * @return string
	 */
	public function sport(): string {
		return $this->sport;
	}

	/**
	 * League name.
	 *
	 * @return string
	 */
	public function league(): string {
		return $this->league;
	}

	/**
	 * Home team.
	 *
	 * @return string
	 */
	public function home(): string {
		return $this->home;
	}

	/**
	 * Away team.
	 *
	 * @return string
	 */
	public function away(): string {
		return $this->away;
	}

	/**
	 * Scheduled start.
	 *
	 * @return DateTimeImmutable
	 */
	public function kickoff(): DateTimeImmutable {
		return $this->kickoff;
	}

	/**
	 * "Home v Away", used as the table caption and editor label.
	 *
	 * @return string
	 */
	public function title(): string {
		return $this->home . ' v ' . $this->away;
	}

	/**
	 * Array form, used by the REST API and the block editor.
	 *
	 * @return array<string,string>
	 */
	public function to_array(): array {
		return array(
			'id'      => $this->id,
			'sport'   => $this->sport,
			'league'  => $this->league,
			'home'    => $this->home,
			'away'    => $this->away,
			'kickoff' => $this->kickoff->format( DATE_ATOM ),
			'title'   => $this->title(),
		);
	}
}
