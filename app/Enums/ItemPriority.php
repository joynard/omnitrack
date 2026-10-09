<?php

namespace App\Enums;

enum ItemPriority: string
{
    case LOW = 'low';
    case MEDIUM = 'medium';
    case HIGH = 'high';

    /**
     * Normalize free-form spreadsheet text into a canonical priority.
     */
    public static function normalize(?string $value): self
    {
        $clean = strtolower(trim((string) $value));

        return match (true) {
            str_contains($clean, 'tinggi'),
            str_contains($clean, 'high'),
            str_contains($clean, 'p1'),
            str_contains($clean, 'urgent') => self::HIGH,
            str_contains($clean, 'sedang'),
            str_contains($clean, 'medium'),
            str_contains($clean, 'p2') => self::MEDIUM,
            default => self::LOW,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::LOW => 'Low',
            self::MEDIUM => 'Medium',
            self::HIGH => 'High',
        };
    }

    /** CSS class hook; HIGH maps to the "urgent" chip from the STYLEGUIDE. */
    public function chipClass(): string
    {
        return match ($this) {
            self::LOW => 'chip chip-pending',
            self::MEDIUM => 'chip chip-progress',
            self::HIGH => 'chip chip-urgent',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $carry, self $case) => $carry + [$case->value => $case->label()],
            []
        );
    }
}
