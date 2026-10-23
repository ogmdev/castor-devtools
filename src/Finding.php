<?php

declare(strict_types=1);

namespace Ogmdev\CastorDevtools;

final readonly class Finding
{
    public function __construct(
        public string $subject,
        public FindingSeverity $severity,
        public string $detail,
        public ?string $remedy = null,
        public ?string $effort = null,
        public ?string $humanHelp = null,
        public ?string $documentationUrl = null,
        public ?string $evidence = null,
        public bool $halts = false,
    ) {}

    public static function ok(string $subject, string $detail): self
    {
        return new self($subject, FindingSeverity::Ok, $detail);
    }

    public function needsAction(): bool
    {
        return $this->severity->needsAction();
    }

    public function isSelfServe(): bool
    {
        return $this->humanHelp === null;
    }

    public function shareLine(): string
    {
        return sprintf('- [%s] %s: %s', $this->severity->label(), $this->subject, $this->detail);
    }
}
