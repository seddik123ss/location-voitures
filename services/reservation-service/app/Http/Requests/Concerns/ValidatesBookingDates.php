<?php

namespace App\Http\Requests\Concerns;

use Carbon\CarbonImmutable;
use Closure;

/** Règle 6 : startDate >= aujourd'hui (heure de Tunis) et endDate > startDate, sinon 400. */
trait ValidatesBookingDates
{
    /** @return array<string, list<mixed>> */
    protected function dateRules(): array
    {
        return [
            'startDate' => ['required', 'date_format:Y-m-d', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && $value < self::today()) {
                    $fail("startDate ne peut pas être dans le passé (aujourd'hui : ".self::today().')');
                }
            }],
            'endDate' => ['required', 'date_format:Y-m-d', 'after:startDate'],
        ];
    }

    /** @return array<string, string> */
    protected function dateMessages(): array
    {
        return [
            'startDate.required' => 'startDate est obligatoire',
            'startDate.date_format' => 'startDate doit être une date au format YYYY-MM-DD',
            'endDate.required' => 'endDate est obligatoire',
            'endDate.date_format' => 'endDate doit être une date au format YYYY-MM-DD',
            'endDate.after' => 'endDate doit être après startDate (au moins un jour de location)',
        ];
    }

    public static function today(): string
    {
        return CarbonImmutable::now(config('reservation.timezone'))->toDateString();
    }

    public function startDate(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $this->input('startDate'), 'UTC');
    }

    public function endDate(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $this->input('endDate'), 'UTC');
    }

    protected function uuidOrNull(string $field): ?string
    {
        $value = $this->input($field);

        return is_string($value) ? strtolower($value) : null;
    }
}
