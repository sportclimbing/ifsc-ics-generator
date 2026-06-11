<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nicolas Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\IcsGenerator;

final readonly class FilterParams
{
    /**
     * @param string[] $disciplines  e.g. ['boulder', 'lead']
     * @param string[] $kinds        e.g. ['qualification', 'final']
     * @param string[] $categories   e.g. ['men', 'women']
     * @param string[] $series       e.g. ['world', 'para']
     */
    public function __construct(
        public array $disciplines = [],
        public array $kinds = [],
        public array $categories = [],
        public array $series = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->disciplines === [] && $this->kinds === [] && $this->categories === [] && $this->series === [];
    }

    /**
     * Parse from $_GET-style query parameters.
     *
     * @param array<string, string> $query
     */
    public static function fromQuery(array $query): self
    {
        return new self(
            disciplines: self::parseCommaList($query['discipline'] ?? ''),
            kinds: self::parseCommaList($query['kind'] ?? ''),
            categories: self::parseCommaList($query['category'] ?? ''),
            series: self::parseCommaList($query['series'] ?? ''),
        );
    }

    /**
     * @return string[]
     */
    private static function parseCommaList(string $value): array
    {
        if ($value === '') {
            return [];
        }

        return array_values(array_filter(
            array_map(trim(...), explode(',', $value)),
            static fn (string $v): bool => $v !== '',
        ));
    }
}
