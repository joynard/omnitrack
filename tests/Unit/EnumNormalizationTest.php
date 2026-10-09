<?php

namespace Tests\Unit;

use App\Enums\ItemPriority;
use App\Enums\ItemStatus;
use PHPUnit\Framework\TestCase;

class EnumNormalizationTest extends TestCase
{
    public function test_status_normalization_accepts_indonesian_and_english_values(): void
    {
        $this->assertSame(ItemStatus::DONE, ItemStatus::normalize('Selesai'));
        $this->assertSame(ItemStatus::DONE, ItemStatus::normalize('DONE'));
        $this->assertSame(ItemStatus::DONE, ItemStatus::normalize('100%'));
        $this->assertSame(ItemStatus::IN_PROGRESS, ItemStatus::normalize('WIP'));
        $this->assertSame(ItemStatus::IN_PROGRESS, ItemStatus::normalize('sedang jalan'));
        $this->assertSame(ItemStatus::IN_PROGRESS, ItemStatus::normalize('In Progress'));
        $this->assertSame(ItemStatus::PENDING, ItemStatus::normalize('Belum mulai'));
    }

    public function test_status_normalization_is_safe_for_null_and_empty_input(): void
    {
        $this->assertSame(ItemStatus::PENDING, ItemStatus::normalize(null));
        $this->assertSame(ItemStatus::PENDING, ItemStatus::normalize(''));
        $this->assertSame(ItemStatus::PENDING, ItemStatus::normalize('   '));
    }

    public function test_priority_normalization_maps_urgency_keywords(): void
    {
        $this->assertSame(ItemPriority::HIGH, ItemPriority::normalize('tinggi'));
        $this->assertSame(ItemPriority::HIGH, ItemPriority::normalize('P1'));
        $this->assertSame(ItemPriority::HIGH, ItemPriority::normalize('urgent'));
        $this->assertSame(ItemPriority::MEDIUM, ItemPriority::normalize('sedang'));
        $this->assertSame(ItemPriority::MEDIUM, ItemPriority::normalize('P2'));
        $this->assertSame(ItemPriority::LOW, ItemPriority::normalize('rendah'));
        $this->assertSame(ItemPriority::LOW, ItemPriority::normalize(null));
    }

    public function test_chip_classes_follow_the_styleguide(): void
    {
        $this->assertSame('chip chip-done', ItemStatus::DONE->chipClass());
        $this->assertSame('chip chip-progress', ItemStatus::IN_PROGRESS->chipClass());
        $this->assertSame('chip chip-pending', ItemStatus::PENDING->chipClass());
        $this->assertSame('chip chip-urgent', ItemPriority::HIGH->chipClass());
    }
}
