<?php

declare(strict_types=1);

namespace Typic\Pagination;

class PaginatorInfo
{
    public function __construct(
        public bool $hasMorePages,
        public int $currentPage,
        public int $lastPage,
        public int $perPage,
        public int $total,
        public ?int $firstItem = null,
        public ?int $lastItem = null,
    ) {
    }
}
