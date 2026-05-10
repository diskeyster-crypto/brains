<?php

declare(strict_types=1);

namespace Modules\Strategy\FilterEngine;

final class FilterResult
{
    public function __construct(
        public string $filterId,
        public bool $enabled,
        public bool $passed,
        public string $severity = 'pass',
        public ?string $reason = null,
        public array $details = [],
        public bool $wouldBlock = false,
        public bool $fatal = false,
        public ?float $scoreDelta = null,
    ) {}

    public function toArray(): array
    {
        return [
            'filter_id'   => $this->filterId,
            'enabled'     => $this->enabled,
            'passed'      => $this->passed,
            'severity'    => $this->severity,
            'reason'      => $this->reason,
            'details'     => $this->details,
            'would_block' => $this->wouldBlock,
            'fatal'       => $this->fatal,
            'score_delta' => $this->scoreDelta,
        ];
    }
}
