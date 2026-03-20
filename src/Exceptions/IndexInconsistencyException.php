<?php

namespace Kura\Exceptions;

/**
 * Thrown when meta indicates an index exists for a column but the APCu key is missing.
 *
 * This signals cache corruption (e.g. APCu eviction under memory pressure).
 * CacheProcessor catches this and triggers a full rebuild + Loader fallback,
 * identical to the CacheInconsistencyException handling path.
 */
class IndexInconsistencyException extends CacheInconsistencyException
{
    public function __construct(
        string $message,
        string $table = '',
        public readonly string $column = '',
    ) {
        parent::__construct($message, $table);
    }
}
