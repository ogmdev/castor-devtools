<?php

declare(strict_types=1);

namespace Ogmdev\CastorDevtools;

final readonly class Tasks
{
    public function __construct(
        public string $executable = 'castor',
        public string $start = 'docker:start',
        public string $status = 'docker:status',
        public string $logs = 'docker:logs',
        public string $tls = 'env:tls',
        public string $hosts = 'env:hosts',
        public string $assetsDev = 'assets:dev',
        public string $doctor = 'dev:doctor',
    ) {}

    public function command(string $task, string $arguments = ''): string
    {
        return trim(sprintf('%s %s %s', $this->executable, $task, $arguments));
    }
}
