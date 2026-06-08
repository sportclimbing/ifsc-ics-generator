<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nicolas Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\IcsGenerator;

final class CalendarFilter
{
    /**
     * @param array<int, array<string, mixed>> $events
     * @return array<int, array<string, mixed>>
     */
    public static function apply(array $events, FilterParams $params): array
    {
        if ($params->isEmpty()) {
            return $events;
        }

        $filtered = [];

        foreach ($events as $event) {
            $rounds = $event['rounds'] ?? [];

            $keptRounds = array_filter(
                $rounds,
                fn (array $round): bool =>
                    self::matchesDisciplines($round, $params->disciplines)
                    && self::matchesKinds($round, $params->kinds)
                    && self::matchesCategories($round, $params->categories),
            );

            if ($keptRounds === []) {
                continue;
            }

            $event['rounds'] = array_values($keptRounds);
            $filtered[] = $event;
        }

        return $filtered;
    }

    /**
     * @param array<string, mixed> $round
     * @param string[] $selected
     */
    private static function matchesDisciplines(array $round, array $selected): bool
    {
        if ($selected === []) {
            return true;
        }

        $roundDisciplines = $round['disciplines'] ?? [];

        return !empty(array_intersect($roundDisciplines, self::expandSpeed($selected)));
    }

    /**
     * @param array<string, mixed> $round
     * @param string[] $selected
     */
    private static function matchesKinds(array $round, array $selected): bool
    {
        if ($selected === []) {
            return true;
        }

        return in_array($round['kind'] ?? '', $selected, strict: true);
    }

    /**
     * @param array<string, mixed> $round
     * @param string[] $selected
     */
    private static function matchesCategories(array $round, array $selected): bool
    {
        if ($selected === []) {
            return true;
        }

        $roundCategories = $round['categories'] ?? [];

        return !empty(array_intersect($roundCategories, $selected));
    }

    /**
     * Expand 'speed' to include 'speed_relay'.
     *
     * @param string[] $disciplines
     * @return string[]
     */
    private static function expandSpeed(array $disciplines): array
    {
        if (in_array('speed', $disciplines, strict: true) && !in_array('speed_relay', $disciplines, strict: true)) {
            $disciplines[] = 'speed_relay';
        }

        return $disciplines;
    }
}
