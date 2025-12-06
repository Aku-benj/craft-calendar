<?php

namespace Solspace\Calendar\Elements\Db;

use Carbon\Carbon;
use craft\db\Table;
use craft\elements\db\ElementQuery;
use craft\helpers\Db;
use Solspace\Calendar\Elements\Event;
use Solspace\Calendar\Elements\EventOverride;

class EventOverrideQuery extends ElementQuery
{
    private const INPUT_FORMATS = [
        // ISO / RFC
        'Y-m-d\TH:i:sP',
        'Y-m-d\TH:i:s\Z',
        \DATE_RFC3339,
        \DATE_ATOM,

        // Common SQL/ISO-ish
        'Y-m-d H:i:s',
        'Y-m-d H:i',
        'Y-m-d',

        // UK/EU (DMY)
        'd/m/Y H:i:s',
        'd/m/Y H:i',
        'd/m/Y',
        'd-m-Y H:i:s',
        'd-m-Y H:i',
        'd-m-Y',
        'd.m.Y H:i:s',
        'd.m.Y H:i',
        'd.m.Y',

        // US (MDY)
        'm/d/Y H:i:s',
        'm/d/Y H:i',
        'm/d/Y',
        'm-d-Y H:i:s',
        'm-d-Y H:i',
        'm-d-Y',
        'm.d.Y H:i:s',
        'm.d.Y H:i',
        'm.d.Y',
    ];

    public ?int $eventId = null;

    private null|Carbon|\DateTime|string $date = null;

    public function eventId(null|int $value = null): self
    {
        $this->eventId = $value;

        return $this;
    }

    public function date(null|Carbon|\DateTime|string $value = null): self
    {
        $this->date = $value;

        return $this;
    }

    protected function beforePrepare(): bool
    {
        $overridesTable = EventOverride::TABLE_STD;
        $eventsTable = Event::TABLE_STD;
        $usersTable = Table::USERS;

        $this->joinElementTable($overridesTable);
        $hasRelations = false;
        $hasUsers = false;

        if (!empty($this->join)) {
            foreach ($this->join as $join) {
                if (Table::RELATIONS.' relations' === $join[1]) {
                    $hasRelations = true;
                }

                if (isset($join[1]['relations']) && Table::RELATIONS === $join[1]['relations']) {
                    $hasRelations = true;
                }

                if (Table::USERS === $join[1]) {
                    $hasUsers = true;
                }
            }
        }

        if (null === $this->join) {
            $this->join = [];
        }

        $this->join[] = ['LEFT JOIN', $eventsTable, "{$eventsTable}.[[id]] = {$overridesTable}.[[eventId]]"];

        if (!$hasUsers) {
            $this->join[] = ['LEFT JOIN', $usersTable, "{$usersTable}.[[id]] = {$overridesTable}.[[authorId]]"];
        }

        $select = [
            $overridesTable.'.[[authorId]]',
            $overridesTable.'.[[eventId]]',
            $overridesTable.'.[[date]]',
            $overridesTable.'.[[startTime]]',
            $overridesTable.'.[[endTime]]',
            $overridesTable.'.[[allDay]]',
            $usersTable.'.[[username]]'
        ];

        if ($hasRelations) {
            $select[] = '[[relations.sortOrder]]';
        }

        // select the price column
        $this->query->select($select);

        if ($this->eventId) {
            $this->subQuery->andWhere(Db::parseParam($overridesTable.'.[[eventId]]', $this->eventId));
        }

        if ($this->date) {
            $this->subQuery->andWhere(
                Db::parseParam(
                    $overridesTable.'.[[date]]',
                    $this->extractDateAsFormattedString($this->date)
                )
            );
        }

        return parent::beforePrepare();
    }

    private function hasExplicitTime(string $value): bool
    {
        // looks for HH:MM or a 'T' with time portion
        return (bool) preg_match('/(?:T|\s)\d{1,2}:\d{2}/', $value);
    }

    /**
     * Normalize a date string (possibly with an operator) to 'Y-m-d H:i:s' UTC.
     * Returns the original trimmed string if it can’t confidently parse it.
     */
    private function normalizeStringDate(string $date, ?\DateTimeZone $defaultTz = null): string
    {
        $date = trim($date);
        if ('' === $date) {
            return $date;
        }

        // Extract optional operator
        $operator = '';
        if (preg_match('/^(<=|>=|<>|<|>|=)\s*(.+)$/', $date, $matches)) {
            $operator = $matches[1];
            $date = trim($matches[2]);
        }

        // Numeric unix timestamp?
        if (ctype_digit($date)) {
            $datetime = (new \DateTimeImmutable('@'.$date))->setTimezone(new \DateTimeZone('UTC'));

            return ltrim($operator.' '.$datetime->format('Y-m-d H:i:s'));
        }

        $hasTime = $this->hasExplicitTime($date);

        $defaultTz ??= new \DateTimeZone('UTC');

        // Try whitelisted formats first
        foreach (self::INPUT_FORMATS as $format) {
            $datetime = \DateTimeImmutable::createFromFormat($format, $date, $defaultTz);
            if ($datetime instanceof \DateTimeImmutable) {
                if (!$hasTime) {
                    $datetime = $datetime->setTime(0, 0, 0);
                }

                // If input had no tz info, $defaultTz is used; convert to UTC for storage
                $datetime = $datetime->setTimezone(new \DateTimeZone('UTC'));

                return ltrim($operator.' '.$datetime->format('Y-m-d H:i:s'));
            }
        }

        // Fallback: let PHP try
        try {
            $datetime = new \DateTimeImmutable($date, $defaultTz);

            if (!$hasTime) {
                $datetime = $datetime->setTime(0, 0, 0);
            }

            $datetime = $datetime->setTimezone(new \DateTimeZone('UTC'));

            return ltrim($operator.' '.$datetime->format('Y-m-d H:i:s'));
        } catch (\Exception) {
            // Give up: return original so Db::parseParam can still handle known operator strings
            return trim(($operator ? $operator.' ' : '').$date);
        }
    }

    private function extractDateAsFormattedString(mixed $date): array|string
    {
        // normalize recursively and preserve operator tokens
        if (\is_array($date)) {
            $normalized = [];

            foreach ($date as $key => $value) {
                // Preserve common logical tokens as-is
                if (\is_string($value) && \in_array(strtolower(trim($value)), ['and', 'or', 'not'], true)) {
                    $normalized[$key] = $value;

                    continue;
                }

                // Recurse for nested arrays or format scalars
                $normalized[$key] = $this->extractDateAsFormattedString($value);
            }

            return $normalized;
        }

        // Carbon -> 'Y-m-d H:i:s'
        if ($date instanceof Carbon) {
            $date = $date->toDateTimeString();
        }

        // DateTime -> 'Y-m-d H:i:s'
        if ($date instanceof \DateTimeInterface) {
            $date = $date->format('Y-m-d H:i:s');
        }

        // Unix timestamp (int)
        if (\is_int($date)) {
            // Use date() or gmdate() depending on your storage conventions
            return date('Y-m-d H:i:s', $date);
        }

        // Strings (including operator strings like '>= 2024-09-01 00:00:00')
        if (\is_string($date)) {
            return $this->normalizeStringDate($date);
        }

        // explicit to help debugging
        throw new \InvalidArgumentException(\sprintf(
            'Invalid date param type: %s',
            \is_object($date) ? $date::class : \gettype($date)
        ));
    }
}
