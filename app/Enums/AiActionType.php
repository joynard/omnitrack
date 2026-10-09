<?php

namespace App\Enums;

enum AiActionType: string
{
    case PARSE_TASK = 'parse_task';
    case BREAKDOWN_PROJECT = 'breakdown_project';
    case SUMMARIZE_BOARD = 'summarize_board';
    case TRIGGER_SYNC = 'trigger_sync';

    /** Full-control agent: create/edit/delete/reorder anything. */
    case AGENT = 'agent';

    public function label(): string
    {
        return match ($this) {
            self::PARSE_TASK => 'Parse Task',
            self::BREAKDOWN_PROJECT => 'Breakdown Project',
            self::SUMMARIZE_BOARD => 'Summarize Board',
            self::TRIGGER_SYNC => 'Trigger Sync',
            self::AGENT => 'Full Control',
        };
    }
}
