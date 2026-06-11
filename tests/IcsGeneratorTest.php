<?php declare(strict_types=1);

namespace SportClimbing\IcsGenerator\Tests;

use PHPUnit\Framework\TestCase;
use Sabre\VObject;
use SportClimbing\IcsGenerator\CalendarFactory;
use SportClimbing\IcsGenerator\CalendarFilter;
use SportClimbing\IcsGenerator\FilterParams;
use SportClimbing\IcsGenerator\IcsGenerator;

final class IcsGeneratorTest extends TestCase
{
    private IcsGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new IcsGenerator(
            new CalendarFactory(),
            '-//IFSC//IFSC Calendar//EN',
            'PT12H',
            'IFSC World Cups and World Championships',
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function sampleEvents(): array
    {
        return [
            [
                'id' => 1524,
                'name' => 'World Climbing Series Test 2026',
                'location' => 'Test City',
                'country' => 'TST',
                'country_name' => 'Testland',
                'site_url' => 'https://ifsc.stream/season/2026/event/test-1524/',
                'starts_at' => '2026-06-01T09:00:00+02:00',
                'ends_at' => '2026-06-03T19:00:00+02:00',
                'timezone' => 'Europe/Zurich',
                'league_name' => 'World Cups and World Championships',
                'tickets' => [
                    'summary' => 'Tickets info',
                    'purchase_url' => 'https://tickets.example.com',
                ],
                'rounds' => [
                    [
                        'name' => "Women's Boulder Qualification",
                        'categories' => ['women'],
                        'disciplines' => ['boulder'],
                        'kind' => 'qualification',
                        'starts_at' => '2026-06-01T09:00:00+02:00',
                        'ends_at' => '2026-06-01T14:00:00+02:00',
                        'schedule_status' => 'confirmed',
                        'stream_url' => 'https://youtube.com/watch?v=test',
                        'stream_blocked_regions' => [],
                    ],
                    [
                        'name' => "Women's Boulder Semi-Final",
                        'categories' => ['women'],
                        'disciplines' => ['boulder'],
                        'kind' => 'semi-final',
                        'starts_at' => '2026-06-02T10:30:00+02:00',
                        'ends_at' => '2026-06-02T13:17:00+02:00',
                        'schedule_status' => 'confirmed',
                        'stream_url' => 'https://youtube.com/watch?v=test2',
                        'stream_blocked_regions' => [],
                    ],
                    [
                        'name' => "Women's Boulder Final",
                        'categories' => ['women'],
                        'disciplines' => ['boulder'],
                        'kind' => 'final',
                        'starts_at' => '2026-06-03T18:00:00+02:00',
                        'ends_at' => '2026-06-03T20:00:00+02:00',
                        'schedule_status' => 'confirmed',
                        'stream_url' => 'https://youtube.com/watch?v=test3',
                        'stream_blocked_regions' => [],
                    ],
                    [
                        'name' => "Women's Lead Qualification",
                        'categories' => ['women'],
                        'disciplines' => ['lead'],
                        'kind' => 'qualification',
                        'starts_at' => '2026-06-01T15:00:00+02:00',
                        'ends_at' => '2026-06-01T20:00:00+02:00',
                        'schedule_status' => 'estimated',
                        'stream_url' => null,
                        'stream_blocked_regions' => [],
                    ],
                ],
                'start_list' => [
                    [
                        'first_name' => 'Jane',
                        'last_name' => 'Doe',
                        'country' => 'TST',
                        'category' => 'women',
                    ],
                ],
            ],
        ];
    }

    private function generateIcs(array $events): string
    {
        return $this->generator->generateForEvents($events);
    }

    // ── Generation tests ────────────────────────────────────────────

    public function test_generates_valid_ics_structure(): void
    {
        $ics = $this->generateIcs($this->sampleEvents());

        $this->assertStringContainsString('BEGIN:VCALENDAR', $ics);
        $this->assertStringContainsString('END:VCALENDAR', $ics);
        $this->assertStringContainsString('PRODID:-//IFSC//IFSC Calendar//EN', $ics);
        $this->assertStringContainsString('VERSION:2.0', $ics);
        $this->assertStringContainsString('CALSCALE:GREGORIAN', $ics);
        $this->assertStringContainsString('X-WR-CALNAME:IFSC World Cups and World Championships', $ics);
    }

    public function test_includes_streamable_rounds(): void
    {
        $ics = $this->generateIcs($this->sampleEvents());

        // Boulder qualification has stream URL → included
        $this->assertStringContainsString("Women's Boulder Qualification", $ics);
        // Semi-final and final are always streamable → included
        $this->assertStringContainsString("Women's Boulder Semi-Final", $ics);
        $this->assertStringContainsString("Women's Boulder Final", $ics);
        // Lead qualification without stream URL → excluded
        $this->assertStringNotContainsString("Women's Lead Qualification", $ics);
    }

    public function test_confirmed_rounds_get_alarm(): void
    {
        $ics = $this->generateIcs($this->sampleEvents());

        // Confirmed rounds should have alarms
        $this->assertStringContainsString('BEGIN:VALARM', $ics);
        $this->assertStringContainsString('TRIGGER;RELATED=END:-PT1H', $ics);
    }

    public function test_games_events_are_excluded(): void
    {
        $events = $this->sampleEvents();
        $events[0]['league_name'] = 'Games';

        $ics = $this->generateIcs($events);

        $this->assertEquals(0, substr_count($ics, 'BEGIN:VEVENT'));
    }

    public function test_event_with_no_streamable_rounds_gets_generic_event(): void
    {
        $events = $this->sampleEvents();
        // Remove all rounds with stream URLs or non-qualification kinds
        $events[0]['rounds'] = [
            [
                'name' => "Women's Lead Qualification",
                'categories' => ['women'],
                'disciplines' => ['lead'],
                'kind' => 'qualification',
                'starts_at' => '2026-06-01T15:00:00+02:00',
                'ends_at' => '2026-06-01T20:00:00+02:00',
                'schedule_status' => 'estimated',
                'stream_url' => null,
                'stream_blocked_regions' => [],
            ],
        ];

        $ics = $this->generateIcs($events);

        // Should have one generic VEVENT (without round name in summary)
        $this->assertEquals(1, substr_count($ics, 'BEGIN:VEVENT'));
        $this->assertStringContainsString('STATUS:TENTATIVE', $ics);
        $this->assertStringContainsString('TRIGGER;RELATED=END:-P1D', $ics);
    }

    public function test_includes_timezone_blocks(): void
    {
        $ics = $this->generateIcs($this->sampleEvents());

        $this->assertStringContainsString('BEGIN:VTIMEZONE', $ics);
        $this->assertStringContainsString('TZID:Europe/Zurich', $ics);
    }

    public function test_description_contains_required_sections(): void
    {
        $ics = $this->generateIcs($this->sampleEvents());

        // Unfold lines for text search (ICS wraps at 75 chars)
        $unfolded = preg_replace("/\r\n\s/", '', $ics);

        $this->assertStringContainsString('Stream URL', $unfolded);
        $this->assertStringContainsString('Buy Tickets', $unfolded);
        $this->assertStringContainsString('Join Discord', $unfolded);
        $this->assertStringContainsString('buymeacoffee.com', $unfolded);
        $this->assertStringContainsString('Report a bug', $unfolded);
    }

    public function test_description_includes_start_list(): void
    {
        $ics = $this->generateIcs($this->sampleEvents());

        // Unfold lines for text search (ICS wraps at 75 chars)
        $unfolded = preg_replace("/\r\n\s/", '', $ics);

        $this->assertStringContainsString('Start List', $unfolded);
        $this->assertStringContainsString('Jane Doe', $unfolded);
        $this->assertStringContainsString('(TST)', $unfolded);
    }

    // ── Start list filtering ────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function sampleAthlete(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'country' => 'TST',
            'category' => 'women',
            'disciplines' => ['boulder'],
        ], $overrides);
    }

    /**
     * @param array<int, array<string, mixed>> $startList
     * @param array<string, mixed>|null $round
     * @return array<int, array<string, mixed>>
     */
    private function invokeGetFilteredStartList(array $startList, ?array $round): array
    {
        $event = ['start_list' => $startList];
        $method = new \ReflectionMethod(IcsGenerator::class, 'getFilteredStartList');
        return $method->invoke($this->generator, $event, $round);
    }

    private function invokeAthleteMatchesRound(array $athlete, array $categories, array $disciplines): bool
    {
        $method = new \ReflectionMethod(IcsGenerator::class, 'athleteMatchesRound');
        return $method->invoke($this->generator, $athlete, $categories, $disciplines);
    }

    public function test_getFilteredStartList_null_round_returns_full_list(): void
    {
        $startList = [$this->sampleAthlete(), $this->sampleAthlete(['first_name' => 'John'])];

        $result = $this->invokeGetFilteredStartList($startList, null);

        $this->assertCount(2, $result);
    }

    public function test_getFilteredStartList_empty_categories_and_disciplines_returns_full_list(): void
    {
        $startList = [$this->sampleAthlete(), $this->sampleAthlete(['first_name' => 'John'])];

        $result = $this->invokeGetFilteredStartList($startList, [
            'categories' => [],
            'disciplines' => [],
        ]);

        $this->assertCount(2, $result);
    }

    public function test_getFilteredStartList_empty_categories_but_has_disciplines_filters(): void
    {
        $boulderAthlete = $this->sampleAthlete(['disciplines' => ['boulder']]);
        $speedAthlete = $this->sampleAthlete(['first_name' => 'Speedo', 'disciplines' => ['speed']]);
        $startList = [$boulderAthlete, $speedAthlete];

        $result = $this->invokeGetFilteredStartList($startList, [
            'categories' => [],
            'disciplines' => ['boulder'],
        ]);

        $this->assertCount(1, $result);
        $this->assertEquals('Jane', reset($result)['first_name']);
    }

    public function test_athleteMatchesRound_category_match_discipline_match(): void
    {
        $athlete = $this->sampleAthlete(['category' => 'women', 'disciplines' => ['boulder']]);

        $result = $this->invokeAthleteMatchesRound($athlete, ['women'], ['boulder']);

        $this->assertTrue($result);
    }

    public function test_athleteMatchesRound_category_mismatch(): void
    {
        $athlete = $this->sampleAthlete(['category' => 'men', 'disciplines' => ['boulder']]);

        $result = $this->invokeAthleteMatchesRound($athlete, ['women'], ['boulder']);

        $this->assertFalse($result);
    }

    public function test_athleteMatchesRound_discipline_mismatch(): void
    {
        $athlete = $this->sampleAthlete(['category' => 'women', 'disciplines' => ['speed']]);

        $result = $this->invokeAthleteMatchesRound($athlete, ['women'], ['boulder']);

        $this->assertFalse($result);
    }

    public function test_athleteMatchesRound_no_category_field_passes(): void
    {
        $athlete = $this->sampleAthlete(['disciplines' => ['boulder']]);
        unset($athlete['category']);

        $result = $this->invokeAthleteMatchesRound($athlete, ['women'], ['boulder']);

        $this->assertTrue($result);
    }

    public function test_athleteMatchesRound_no_disciplines_field_passes(): void
    {
        $athlete = $this->sampleAthlete(['category' => 'women']);
        unset($athlete['disciplines']);

        $result = $this->invokeAthleteMatchesRound($athlete, ['women'], ['boulder']);

        $this->assertTrue($result);
    }

    public function test_athleteMatchesRound_empty_categories_passes_category_check(): void
    {
        $athlete = $this->sampleAthlete(['category' => 'men', 'disciplines' => ['boulder']]);

        $result = $this->invokeAthleteMatchesRound($athlete, [], ['boulder']);

        $this->assertTrue($result);
    }

    public function test_athleteMatchesRound_empty_disciplines_passes_discipline_check(): void
    {
        $athlete = $this->sampleAthlete(['category' => 'women', 'disciplines' => ['speed']]);

        $result = $this->invokeAthleteMatchesRound($athlete, ['women'], []);

        $this->assertTrue($result);
    }

    public function test_athleteMatchesRound_both_mismatch(): void
    {
        $athlete = $this->sampleAthlete(['category' => 'men', 'disciplines' => ['speed']]);

        $result = $this->invokeAthleteMatchesRound($athlete, ['women'], ['boulder']);

        $this->assertFalse($result);
    }

    public function test_athleteMatchesRound_athlete_has_multiple_disciplines_one_matches(): void
    {
        $athlete = $this->sampleAthlete(['category' => 'women', 'disciplines' => ['boulder', 'lead']]);

        $result = $this->invokeAthleteMatchesRound($athlete, ['women'], ['lead']);

        $this->assertTrue($result);
    }

    public function test_athleteMatchesRound_round_has_multiple_categories_one_matches(): void
    {
        $athlete = $this->sampleAthlete(['category' => 'women', 'disciplines' => ['boulder']]);

        $result = $this->invokeAthleteMatchesRound($athlete, ['women', 'men'], ['boulder']);

        $this->assertTrue($result);
    }

    // ── ICS validation with sabre/vobject ───────────────────────────

    public function test_ics_is_valid_per_sabre_vobject(): void
    {
        $ics = $this->generateIcs($this->sampleEvents());

        $vcalendar = VObject\Reader::read($ics);
        $this->assertInstanceOf(VObject\Component\VCalendar::class, $vcalendar);

        // Should have VEVENT components
        $vevents = $vcalendar->select('VEVENT');
        $this->assertGreaterThan(0, count($vevents));

        // Each VEVENT should have required properties
        foreach ($vevents as $vevent) {
            $this->assertNotNull($vevent->UID, 'VEVENT missing UID');
            $this->assertNotNull($vevent->DTSTART, 'VEVENT missing DTSTART');
            $this->assertNotNull($vevent->DTEND, 'VEVENT missing DTEND');
            $this->assertNotNull($vevent->SUMMARY, 'VEVENT missing SUMMARY');
        }
    }

    public function test_provisional_round_has_proper_description(): void
    {
        $events = $this->sampleEvents();
        $events[0]['rounds'][0]['schedule_status'] = 'provisional';

        $ics = $this->generateIcs($events);

        // Unfold for text search
        $unfolded = preg_replace("/\r\n\s/", '', $ics);
        $this->assertStringContainsString('Schedule is provisional', $unfolded);
    }

    public function test_ics_has_correct_content_type_properties(): void
    {
        $ics = $this->generateIcs($this->sampleEvents());

        $vcalendar = VObject\Reader::read($ics);
        $this->assertEquals('2.0', (string) $vcalendar->VERSION);
    }

    // ── Real data tests ─────────────────────────────────────────────

    public function test_real_calendar_json_generates_valid_ics(): void
    {
        $jsonFile = __DIR__ . '/fixtures/sample-calendar.json';

        if (!file_exists($jsonFile)) {
            $this->markTestSkipped('Sample calendar fixture not available — run in CI or with real data');
        }

        $json = json_decode(file_get_contents($jsonFile), true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('events', $json);

        $ics = $this->generateIcs($json['events']);

        // Validate with sabre/vobject
        $vcalendar = VObject\Reader::read($ics);
        $this->assertInstanceOf(VObject\Component\VCalendar::class, $vcalendar);

        $vevents = $vcalendar->select('VEVENT');
        $this->assertGreaterThan(0, count($vevents), 'Should have at least one VEVENT');
    }
}
