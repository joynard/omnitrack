<?php

namespace App\Models;

use App\Enums\TelegramMessageStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One incoming Telegram update, kept as an audit trail.
 *
 * @property int $update_id
 * @property string|null $chat_id
 * @property TelegramMessageStatus $status
 */
class TelegramMessage extends Model
{
    use HasUlids;

    protected $fillable = [
        'update_id',
        'chat_id',
        'telegram_user_id',
        'telegram_username',
        'text',
        'reply',
        'status',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'update_id' => 'integer',
            'status' => TelegramMessageStatus::class,
        ];
    }

    /**
     * The offset the next long poll must ask for.
     *
     * Telegram confirms (and drops) every update below the offset it is given,
     * so the highest id already stored plus one is exactly right -- and because
     * a row is written before the instruction runs, an interrupted poll
     * re-delivers instead of losing, which the unique index then dedupes.
     */
    public static function nextOffset(): int
    {
        return (int) (static::max('update_id') ?? -1) + 1;
    }
}
