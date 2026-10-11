<?php

declare(strict_types=1);

namespace Typic\Pagination;

/**
 * @template T
 */
class Edge
{
    /**
     * @param T $node
     */
    public function __construct(
        public mixed $node,
        public string $cursor,
    ) {
    }
}
