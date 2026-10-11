<?php

declare(strict_types=1);

namespace Typic\Pagination;

/**
 * @template T
 */
class Paginator
{
    /**
     * @param array<T>|iterable<T> $data
     */
    public function __construct(
        public iterable $data,
        public PaginatorInfo $paginatorInfo,
    ) {
    }

    /**
     * Creates a Paginator instance from raw items and total, or any paginator object.
     *
     * @param array<T>|iterable<T> $items
     * @return self<T>
     */
    public static function from(
        iterable $items,
        int $currentPage = 1,
        int $perPage = 15,
        ?int $total = null,
    ): self {
        $arrayItems = is_array($items) ? $items : iterator_to_array($items);
        $count = count($arrayItems);
        $totalItems = $total ?? $count;
        $lastPage = max((int) ceil($totalItems / max($perPage, 1)), 1);
        $firstItem = $count > 0 ? (($currentPage - 1) * $perPage) + 1 : null;
        $lastItem = $count > 0 ? $firstItem + $count - 1 : null;

        $info = new PaginatorInfo(
            hasMorePages: $currentPage < $lastPage,
            currentPage: $currentPage,
            lastPage: $lastPage,
            perPage: $perPage,
            total: $totalItems,
            firstItem: $firstItem,
            lastItem: $lastItem,
        );

        return new self($arrayItems, $info);
    }
}
