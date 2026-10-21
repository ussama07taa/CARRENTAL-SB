<?php

namespace App\Support\Reservations;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RequestPeriod
{
    public const TIMEZONE = 'Africa/Casablanca';

    public function validate(Request $request, bool $allowPast = false): array
    {
        $input = $request->validate(['starts_local' => ['required', 'date_format:Y-m-d\TH:i'], 'ends_local' => ['required', 'date_format:Y-m-d\TH:i']], ['required' => 'Ce champ est obligatoire.', 'date_format' => 'Saisissez une date et une heure valides.']);
        $start = $this->instant($input['starts_local'], 'starts_local');
        $end = $this->instant($input['ends_local'], 'ends_local');
        if (! $allowPast && $start->setTimezone(self::TIMEZONE)->toDateString() < CarbonImmutable::today(self::TIMEZONE)->toDateString()) {
            throw ValidationException::withMessages(['starts_local' => 'La date de départ est passée. Choisissez une nouvelle date, aujourd’hui ou plus tard (heure du Maroc).']);
        }
        if ($end <= $start) {
            throw ValidationException::withMessages(['ends_local' => 'Le retour doit être après le départ.']);
        }

        return ['starts_at' => $start->toIso8601String(), 'ends_at' => $end->toIso8601String(), 'starts_local' => $input['starts_local'], 'ends_local' => $input['ends_local'], 'timezone' => self::TIMEZONE, 'duration_minutes' => (int) $start->diffInMinutes($end)];
    }

    public function instant(string $local, string $field): CarbonImmutable
    {
        $naive = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $local, 'UTC')->getTimestamp();
        $offsets = array_unique(array_column((new DateTimeZone(self::TIMEZONE))->getTransitions($naive - 86400, $naive + 86400), 'offset'));
        $matches = [];
        foreach ($offsets as $offset) {
            $candidate = CarbonImmutable::createFromTimestampUTC($naive - $offset);
            if ($candidate->setTimezone(self::TIMEZONE)->format('Y-m-d\TH:i') === $local) {
                $matches[] = $candidate;
            }
        }
        if (count($matches) !== 1) {
            throw ValidationException::withMessages([$field => 'Cette heure est ambiguë ou inexistante au changement d’heure au Maroc. Choisissez un autre horaire.']);
        }

        return $matches[0];
    }
}
