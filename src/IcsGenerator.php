<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nicolas Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\IcsGenerator;

use DateInterval;
use DateMalformedStringException;
use DateTimeImmutable;
use DateTimeZone;
use Eluceo\iCal\Domain\Entity\Calendar;
use Eluceo\iCal\Domain\Entity\Event;
use Eluceo\iCal\Domain\Entity\TimeZone;
use Eluceo\iCal\Domain\Enum\EventStatus;
use Eluceo\iCal\Domain\ValueObject\Alarm;
use Eluceo\iCal\Domain\ValueObject\Alarm\DisplayAction;
use Eluceo\iCal\Domain\ValueObject\Alarm\RelativeTrigger;
use Eluceo\iCal\Domain\ValueObject\DateTime;
use Eluceo\iCal\Domain\ValueObject\Location;
use Eluceo\iCal\Domain\ValueObject\TimeSpan;
use Eluceo\iCal\Domain\ValueObject\Uri;
use Exception;

final readonly class IcsGenerator
{
    private const string DISCORD_URL = 'https://discord.gg/rbM5vjcVHM';

    public function __construct(
        private CalendarFactory $calendarFactory,
        private string $productIdentifier,
        private string $publishedTtl,
        private string $calendarName,
    ) {}

    /**
     * Generate ICS string from an array of event arrays (as decoded from the calendar JSON).
     *
     * @param array<int, array<string, mixed>> $events
     * @throws Exception
     */
    public function generateForEvents(array $events): string
    {
        return (string) $this->calendarFactory->createNamedCalendar(
            $this->createCalendarFromEvents($events),
            $this->calendarName,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $events
     * @throws Exception
     */
    private function createCalendarFromEvents(array $events): Calendar
    {
        $calendar = new Calendar($this->createEvents($events));
        $calendar->setProductIdentifier($this->productIdentifier);
        $calendar->setPublishedTTL(new DateInterval($this->publishedTtl));

        $begin = new DateTimeImmutable('first day of January this year');
        $end = new DateTimeImmutable('last day of December next year');

        foreach ($this->collectTimeZones($events) as $timeZone) {
            $calendar->addTimeZone(TimeZone::createFromPhpDateTimeZone($timeZone, $begin, $end));
        }

        return $calendar;
    }

    /**
     * @param array<int, array<string, mixed>> $events
     * @return array<string, DateTimeZone>
     */
    private function collectTimeZones(array $events): array
    {
        $timeZones = [];

        foreach ($events as $event) {
            $tzName = $event['timezone'] ?? '';
            if ($tzName === '') {
                continue;
            }

            try {
                $timeZones[$tzName] = new DateTimeZone($tzName);
            } catch (Exception) {
                // Skip invalid timezone names
            }
        }

        return $timeZones;
    }

    /**
     * @param array<int, array<string, mixed>> $events
     * @return Event[]
     * @throws Exception
     */
    private function createEvents(array $events): array
    {
        return array_merge(...array_map(
            fn (array $event): array => $this->eventToCalendarEntries($event),
            array_filter($events, fn (array $event): bool => !$this->shouldIgnoreEvent($event)),
        ));
    }

    /**
     * @param array<string, mixed> $event
     * @return Event[]
     * @throws Exception
     */
    private function eventToCalendarEntries(array $event): array
    {
        $rounds = $this->getStreamableRounds($event);

        if ($rounds !== []) {
            return array_map(fn (array $round): Event => $this->createEvent($event, $round), $rounds);
        }

        return [$this->createEventWithoutRounds($event)];
    }

    /**
     * @param array<string, mixed> $event
     * @param array<string, mixed> $round
     * @throws Exception
     */
    private function createEvent(array $event, array $round): Event
    {
        $summary = sprintf("%s - %s (%s)", $round['name'], $event['location'], $event['country']);
        $calendarEvent = new Event()
            ->setSummary($summary)
            ->setDescription($this->buildDescription($event, $round))
            ->setUrl(new Uri($this->buildSiteUrl($event)))
            ->setStatus($this->getEventStatus($round))
            ->setLocation(new Location($this->buildLocation($event)))
            ->setOccurrence($this->buildTimeSpan($round, $event));

        if ($this->isConfirmed($round)) {
            $calendarEvent->addAlarm($this->createAlarm($event, $summary, timeBefore: '1 hour'));
        }

        return $calendarEvent;
    }

    /**
     * @param array<string, mixed> $event
     * @throws Exception
     */
    private function createEventWithoutRounds(array $event): Event
    {
        $eventName = $event['name'] ?? '';
        $summary = sprintf('%s (%s)', $eventName, $event['country']);

        return new Event()
            ->setSummary($summary)
            ->setDescription($this->buildDescription($event))
            ->setUrl(new Uri($this->buildSiteUrl($event)))
            ->setStatus(EventStatus::TENTATIVE())
            ->setLocation(new Location($this->buildLocation($event)))
            ->setOccurrence($this->buildGenericTimeSpan($event))
            ->addAlarm($this->createAlarm($event, $summary, timeBefore: '1 day'));
    }

    /**
     * @param array<string, mixed> $round
     * @param array<string, mixed> $event
     * @throws Exception
     */
    private function buildTimeSpan(array $round, array $event): TimeSpan
    {
        return new TimeSpan(
            new DateTime($this->createDateTime($round['starts_at'], $event), applyTimeZone: true),
            new DateTime($this->createDateTime($round['ends_at'], $event), applyTimeZone: true),
        );
    }

    /**
     * @param array<string, mixed> $event
     * @throws Exception
     */
    private function buildGenericTimeSpan(array $event): TimeSpan
    {
        return new TimeSpan(
            new DateTime($this->createDateTime($event['starts_at'], $event), applyTimeZone: true),
            new DateTime($this->createDateTime($event['ends_at'], $event), applyTimeZone: true),
        );
    }

    /**
     * @param array<string, mixed> $event
     * @throws DateMalformedStringException
     */
    private function createDateTime(string $isoString, array $event): DateTimeImmutable
    {
        $tzName = $event['timezone'] ?? '';

        // Strip timezone offset from ISO string so the named timezone can be applied.
        // e.g. "2026-05-02T10:30:00+08:00" → "2026-05-02T10:30:00"
        if (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})/', $isoString, $m)) {
            $isoString = $m[1];
        }

        if ($tzName !== '') {
            try {
                return new DateTimeImmutable($isoString, new DateTimeZone($tzName));
            } catch (Exception) {
            }
        }

        return new DateTimeImmutable($isoString);
    }

    /**
     * @param array<string, mixed> $event
     * @param array<string, mixed>|null $round
     */
    private function buildDescription(array $event, ?array $round = null): string
    {
        $eventName = $event['name'] ?? '';
        $description = "{$eventName}\n\n";

        if ($round !== null && $this->isProvisional($round)) {
            $description .= "⚠️ Schedule is provisional and might change. ";
            $description .= "This calendar will update automatically once it's confirmed!\n\n";
        } elseif ($round === null) {
            $description .= "⚠️ Precise schedule has not been announced yet. ";
            $description .= "This calendar will update automatically once it's published!\n\n";
        }

        $description .= "🍿 Stream URL:\n{$this->buildSiteUrl($event)}\n\n";

        $ticketsUrl = $event['tickets']['purchase_url'] ?? '';

        if ($ticketsUrl !== '') {
            $description .= "🎟️ Buy Tickets:\n{$ticketsUrl}\n\n";
        }

        $description .= "🔮 Fantasy Climbing League:\n";
        $description .= "https://fantasyclimbingleague.com/\n\n";

        $description .= "☕️ If you find this useful, please consider buying me a coffee:\n";
        $description .= "https://buymeacoffee.com/sportclimbing\n\n";

        $description .= "💬 Join Discord:\n" . self::DISCORD_URL . "\n\n";

        $description .= "🐛 Report a bug/problem:\n";
        $description .= "https://github.com/sportclimbing/ifsc-calendar/issues\n";

        $startList = $this->getFilteredStartList($event, $round);

        if ($startListSlice = array_slice($startList, 0, 20)) {
            $description .= "\n📋 Start List:\n";

            foreach ($startListSlice as $athlete) {
                $description .= sprintf(
                    " - %s %s (%s)\n",
                    $athlete['first_name'] ?? '',
                    $athlete['last_name'] ?? '',
                    $athlete['country'] ?? '',
                );
            }

            $description .= " - ...\n";
        }

        return $description;
    }

    /**
     * @param array<string, mixed> $event
     * @param array<string, mixed>|null $round
     * @return array<int, array<string, mixed>>
     */
    private function getFilteredStartList(array $event, ?array $round): array
    {
        $startList = $event['start_list'] ?? [];

        if ($round === null || ($round['categories'] ?? []) === []) {
            return $startList;
        }

        return array_filter(
            $startList,
            fn (array $athlete): bool => empty($athlete['category'])
                || in_array($athlete['category'], $round['categories'], strict: true),
        );
    }

    /**
     * @param array<string, mixed> $event
     */
    private function buildLocation(array $event): string
    {
        $countryName = $event['country_name'] ?? '';

        return $countryName !== ''
            ? "{$event['location']}, {$countryName}"
            : "{$event['location']} ({$event['country']})";
    }

    /**
     * @param array<string, mixed> $event
     */
    private function buildSiteUrl(array $event): string
    {
        $siteUrl = $event['site_url'] ?? '';
        $separator = str_contains($siteUrl, '?') ? '&' : '?';

        return "{$siteUrl}{$separator}utm_source=calendar";
    }

    /**
     * @param array<string, mixed> $round
     */
    private function getEventStatus(array $round): EventStatus
    {
        return $this->isConfirmed($round)
            ? EventStatus::CONFIRMED()
            : EventStatus::TENTATIVE();
    }

    /**
     * @param array<string, mixed> $event
     * @return array<int, array<string, mixed>>
     */
    private function getStreamableRounds(array $event): array
    {
        return array_filter(
            $event['rounds'] ?? [],
            $this->roundIsStreamable(...),
        );
    }

    /**
     * @param array<string, mixed> $round
     */
    private function roundIsStreamable(array $round): bool
    {
        $kind = $round['kind'] ?? '';

        return $kind !== 'qualification' || !empty($round['stream_url'] ?? null);
    }

    /**
     * @param array<string, mixed> $event
     */
    private function createAlarm(array $event, string $summary, string $timeBefore): Alarm
    {
        $trigger = new RelativeTrigger(
            DateInterval::createFromDateString(datetime: "-{$timeBefore}"),
        );

        return new Alarm(
            new DisplayAction(
                description: "Reminder: {$summary} starts in {$timeBefore}!"
            ),
            $trigger->withRelationToEnd(),
        );
    }

    /**
     * @param array<string, mixed> $event
     */
    private function shouldIgnoreEvent(array $event): bool
    {
        return ($event['league_name'] ?? '') === 'Games';
    }

    /**
     * @param array<string, mixed> $round
     */
    private function isConfirmed(array $round): bool
    {
        return ($round['schedule_status'] ?? '') === 'confirmed';
    }

    /**
     * @param array<string, mixed> $round
     */
    private function isProvisional(array $round): bool
    {
        return ($round['schedule_status'] ?? '') === 'provisional';
    }
}
