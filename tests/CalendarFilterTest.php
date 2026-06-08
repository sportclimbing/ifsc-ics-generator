<?php declare(strict_types=1);

namespace SportClimbing\IcsGenerator\Tests;

use PHPUnit\Framework\TestCase;
use SportClimbing\IcsGenerator\CalendarFilter;
use SportClimbing\IcsGenerator\FilterParams;

final class CalendarFilterTest extends TestCase
{
    /** @return array<int, array<string, mixed>> */
    private function sampleEvents(): array
    {
        return [
            [
                'id' => 1,
                'name' => 'Boulder Event',
                'location' => 'City A',
                'country' => 'TST',
                'country_name' => 'Testland',
                'site_url' => 'https://ifsc.stream/event/1',
                'starts_at' => '2026-06-01T09:00:00+02:00',
                'ends_at' => '2026-06-03T19:00:00+02:00',
                'timezone' => 'Europe/Zurich',
                'league_name' => 'World Cups and World Championships',
                'tickets' => ['summary' => '', 'purchase_url' => ''],
                'rounds' => [
                    [
                        'name' => 'Boulder Qual',
                        'categories' => ['men'],
                        'disciplines' => ['boulder'],
                        'kind' => 'qualification',
                        'starts_at' => '2026-06-01T09:00:00+02:00',
                        'ends_at' => '2026-06-01T14:00:00+02:00',
                        'schedule_status' => 'confirmed',
                        'stream_url' => 'https://youtube.com/test',
                        'stream_blocked_regions' => [],
                    ],
                    [
                        'name' => 'Boulder Final',
                        'categories' => ['men'],
                        'disciplines' => ['boulder'],
                        'kind' => 'final',
                        'starts_at' => '2026-06-03T18:00:00+02:00',
                        'ends_at' => '2026-06-03T20:00:00+02:00',
                        'schedule_status' => 'confirmed',
                        'stream_url' => 'https://youtube.com/test2',
                        'stream_blocked_regions' => [],
                    ],
                ],
                'start_list' => [],
            ],
            [
                'id' => 2,
                'name' => 'Speed Event',
                'location' => 'City B',
                'country' => 'TST',
                'country_name' => 'Testland',
                'site_url' => 'https://ifsc.stream/event/2',
                'starts_at' => '2026-07-01T09:00:00+02:00',
                'ends_at' => '2026-07-01T17:00:00+02:00',
                'timezone' => 'Europe/Zurich',
                'league_name' => 'World Cups and World Championships',
                'tickets' => ['summary' => '', 'purchase_url' => ''],
                'rounds' => [
                    [
                        'name' => 'Speed Final',
                        'categories' => ['men', 'women'],
                        'disciplines' => ['speed'],
                        'kind' => 'final',
                        'starts_at' => '2026-07-01T14:00:00+02:00',
                        'ends_at' => '2026-07-01T16:00:00+02:00',
                        'schedule_status' => 'confirmed',
                        'stream_url' => 'https://youtube.com/test3',
                        'stream_blocked_regions' => [],
                    ],
                    [
                        'name' => 'Speed Relay Final',
                        'categories' => ['men', 'women'],
                        'disciplines' => ['speed_relay'],
                        'kind' => 'final',
                        'starts_at' => '2026-07-01T16:00:00+02:00',
                        'ends_at' => '2026-07-01T17:00:00+02:00',
                        'schedule_status' => 'confirmed',
                        'stream_url' => 'https://youtube.com/test4',
                        'stream_blocked_regions' => [],
                    ],
                ],
                'start_list' => [],
            ],
        ];
    }

    // ── Empty params ────────────────────────────────────────────────

    public function test_empty_params_returns_all_events(): void
    {
        $params = new FilterParams();
        $result = CalendarFilter::apply($this->sampleEvents(), $params);

        $this->assertCount(2, $result);
    }

    // ── Discipline filtering ────────────────────────────────────────

    public function test_discipline_filter_includes_only_matching(): void
    {
        $params = new FilterParams(disciplines: ['boulder']);
        $result = CalendarFilter::apply($this->sampleEvents(), $params);

        $this->assertCount(1, $result);
        $this->assertEquals(1, $result[0]['id']);
    }

    public function test_speed_filter_includes_speed_relay(): void
    {
        $params = new FilterParams(disciplines: ['speed']);
        $result = CalendarFilter::apply($this->sampleEvents(), $params);

        // Both speed and speed_relay rounds should be in the speed event
        $this->assertCount(1, $result);
        $this->assertEquals(2, $result[0]['id']);
        // Should have both rounds
        $this->assertCount(2, $result[0]['rounds']);
    }

    // ── Kind filtering ──────────────────────────────────────────────

    public function test_kind_filter_only_keeps_matching_rounds(): void
    {
        $params = new FilterParams(kinds: ['final']);
        $result = CalendarFilter::apply($this->sampleEvents(), $params);

        $this->assertCount(2, $result);
        // Boulder event should only have the Final round
        $this->assertCount(1, $result[0]['rounds']);
        $this->assertEquals('Boulder Final', $result[0]['rounds'][0]['name']);
    }

    // ── Category filtering ──────────────────────────────────────────

    public function test_category_filter_matches_men_rounds(): void
    {
        $params = new FilterParams(categories: ['men']);
        $result = CalendarFilter::apply($this->sampleEvents(), $params);

        // Both events have men's rounds
        $this->assertCount(2, $result);
    }

    public function test_category_filter_excludes_women_only_rounds(): void
    {
        $events = $this->sampleEvents();
        // Make first event women-only
        $events[0]['rounds'][0]['categories'] = ['women'];
        $events[0]['rounds'][1]['categories'] = ['women'];

        $params = new FilterParams(categories: ['men']);
        $result = CalendarFilter::apply($events, $params);

        // Only speed event should remain (has men in dual-category round)
        $this->assertCount(1, $result);
        $this->assertEquals('Speed Event', $result[0]['name']);
    }

    // ── Combined filters ────────────────────────────────────────────

    public function test_combined_filters(): void
    {
        $params = new FilterParams(
            disciplines: ['boulder'],
            kinds: ['final'],
            categories: ['men'],
        );
        $result = CalendarFilter::apply($this->sampleEvents(), $params);

        $this->assertCount(1, $result);
        $this->assertCount(1, $result[0]['rounds']);
        $this->assertEquals('Boulder Final', $result[0]['rounds'][0]['name']);
    }

    public function test_no_matching_rounds_excludes_event(): void
    {
        $params = new FilterParams(disciplines: ['lead']);
        $result = CalendarFilter::apply($this->sampleEvents(), $params);

        $this->assertCount(0, $result);
    }

    // ── FilterParams ────────────────────────────────────────────────

    public function test_filter_params_from_query(): void
    {
        $params = FilterParams::fromQuery([
            'discipline' => 'boulder,lead',
            'kind' => 'final',
        ]);

        $this->assertEquals(['boulder', 'lead'], $params->disciplines);
        $this->assertEquals(['final'], $params->kinds);
        $this->assertEmpty($params->categories);
    }

    public function test_filter_params_isEmpty(): void
    {
        $empty = new FilterParams();
        $this->assertTrue($empty->isEmpty());

        $notEmpty = new FilterParams(disciplines: ['boulder']);
        $this->assertFalse($notEmpty->isEmpty());
    }

    public function test_filter_params_handles_empty_query(): void
    {
        $params = FilterParams::fromQuery([]);

        $this->assertTrue($params->isEmpty());
        $this->assertEmpty($params->disciplines);
        $this->assertEmpty($params->kinds);
        $this->assertEmpty($params->categories);
    }
}
