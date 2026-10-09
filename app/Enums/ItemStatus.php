<?php

namespace App\Enums;

enum ItemStatus: string
{
    case PENDING = 'pending';
    case IN_PROGRESS = 'in_progress';
    case DONE = 'done';

    /**
     * Normalize free-form spreadsheet text into a canonical status.
     * Handles the mixed Indonesian/English values present in the source sheet.
     */
    public static function normalize(?string $value): self
    {
        $clean = strtolower(trim((string) $value));

        return match (true) {
            str_contains($clean, 'selesai'),
            str_contains($clean, 'done'),
            str_contains($clean, '100%') => self::DONE,
            str_contains($clean, 'wip'),
            str_contains($clean, 'progress'),
            str_contains($clean, 'jalan') => self::IN_PROGRESS,
            default => self::PENDING,
        };
    }

    /** Human-facing label used by the Blade views. */
    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::IN_PROGRESS => 'In Progress',
            self::DONE => 'Done',
        };
    }

    /** CSS class hook matching the STYLEGUIDE status chips. */
    public function chipClass(): string
    {
        return match ($this) {
            self::PENDING => 'chip chip-pending',
            self::IN_PROGRESS => 'chip chip-progress',
            self::DONE => 'chip chip-done',
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
