<?php

namespace App\Enums;

/**
 * Outcome of one incoming Telegram update.
 *
 * `handled` and `failed` both mean a reply was produced. `failed` additionally
 * means the instruction itself errored (missing DeepSeek key, unknown tool,
 * a rejected Telegram send), which is what `telegram:status` surfaces.
 */
enum TelegramMessageStatus: string
{
    case HANDLED = 'handled';
    case IGNORED = 'ignored';
    case FAILED = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::HANDLED => 'Handled',
            self::IGNORED => 'Ignored',
            self::FAILED => 'Failed',
        };
    }
}
