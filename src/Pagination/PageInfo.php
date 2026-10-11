<?php

declare(strict_types=1);

namespace Typic\Pagination;

class PageInfo
{
    public function __construct(
        public bool $hasNextPage,
        public bool $hasPreviousPage,
        public ?string $startCursor = null,
        public ?string $endCursor = null,
    ) {
    }
}
