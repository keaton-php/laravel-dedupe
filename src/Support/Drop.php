<?php

declare(strict_types=1);

namespace Keaton\Dedupe\Support;

/**
 * A dropped record waiting for the end of the run, when its final survivor is known.
 *
 * @internal
 */
final readonly class Drop
{
    public function __construct(
        public string $droppedId,
        public string $droppedHash,
        public ?int $droppedTime,
        public string $matchedId,
        public string $matchedKey,
        public string $matchedValue,
        public ?int $secondsApart,
        public int $slot,
    ) {
        //
    }
}
