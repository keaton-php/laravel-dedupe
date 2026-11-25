<?php

declare(strict_types=1);

namespace Keaton\Dedupe\Support;

/**
 * The record currently standing for one group of duplicates during a run.
 *
 * @internal
 */
class Candidate
{
    /**
     * @param  array<string, string>  $values  match key name => normalized value
     * @param  int|null  $anchor  Timestamp of the first record in the group. The window is measured from here.
     */
    public function __construct(
        public mixed $record,
        public string $id,
        public int $position,
        public ?int $time,
        public ?int $anchor,
        public array $values,
    ) {
        //
    }
}
