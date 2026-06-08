# IFSC ICS Generator

[![Packagist Version](https://img.shields.io/packagist/v/sportclimbing/ifsc-ics-generator)](https://packagist.org/packages/sportclimbing/ifsc-ics-generator)

Shared PHP library that converts IFSC competition calendar JSON into ICS (iCalendar) files. Powers the calendar feeds at [ifsc.stream](https://ifsc.stream).

## Installation

```bash
composer require sportclimbing/ifsc-ics-generator
```

Requires PHP >= 8.5.

## Usage

```php
use SportClimbing\IcsGenerator\CalendarFactory;
use SportClimbing\IcsGenerator\IcsGenerator;

$generator = new IcsGenerator(
    calendarFactory: new CalendarFactory(),
    productIdentifier: '-//YourApp//IFSC Calendar//EN',
    publishedTtl: 'PT12H',
    calendarName: 'IFSC World Cup',
);

// Pass decoded IFSC calendar JSON array
$ics = $generator->generateForEvents($events);

// Filter events by discipline, kind, or category
use SportClimbing\IcsGenerator\CalendarFilter;
use SportClimbing\IcsGenerator\FilterParams;

$params = new FilterParams(
    disciplines: ['boulder', 'lead'],
    kinds: ['final'],
    categories: ['men', 'women'],
);

$filtered = CalendarFilter::apply($events, $params);
$ics = $generator->generateForEvents($filtered);
```
