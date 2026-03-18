<?php

namespace Kura\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kura\KuraManager;

class RebuildCacheJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $table,
        public readonly ?string $version = null,
    ) {}

    public function handle(KuraManager $manager): void
    {
        if ($this->version !== null) {
            $manager->setVersionOverride($this->version);
        }

        $manager->rebuild($this->table);
    }
}
