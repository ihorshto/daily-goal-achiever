<?php

declare(strict_types=1);

namespace App\Stickers;

use App\Models\StickerSet;

final readonly class StickerImport
{
    public function __construct(
        public StickerSet $set,
        public int $count,
        public bool $created,
    ) {}

    /** The confirmation shown to the user. */
    public function message(bool $stickersEnabled): string
    {
        return ($this->created ? 'Added' : 'Updated')." «{$this->set->title}»: {$this->count} stickers."
            .($stickersEnabled
                ? " I'll use them now and then; /stickers off turns this off."
                : ' Stickers are currently off; /stickers on lets me use them.');
    }
}
