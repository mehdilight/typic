<?php

declare(strict_types=1);

namespace Typic\Pagination;

/**
 * @template T
 */
class Connection
{
    /**
     * @param array<Edge<T>> $edges
     */
    public function __construct(
        public array $edges,
        public PageInfo $pageInfo,
        public ?int $totalCount = null,
    ) {
    }

    /**
     * @param array<T>|iterable<T> $items
     * @param callable(T, int): string|null $cursorCallback
     * @return self<T>
     */
    public static function from(
        iterable $items,
        bool $hasNextPage = false,
        bool $hasPreviousPage = false,
        ?int $totalCount = null,
        ?callable $cursorCallback = null,
    ): self {
        $arrayItems = is_array($items) ? $items : iterator_to_array($items);
        $edges = [];
        $cursorCallback ??= static fn (mixed $item, int $index): string => base64_encode((string) (is_object($item) && isset($item->id) ? $item->id : $index));

        $index = 0;
        foreach ($arrayItems as $item) {
            $cursor = (string) $cursorCallback($item, $index);
            $edges[] = new Edge($item, $cursor);
            $index++;
        }

        $startCursor = !empty($edges) ? $edges[0]->cursor : null;
        $endCursor = !empty($edges) ? $edges[count($edges) - 1]->cursor : null;

        $pageInfo = new PageInfo(
            hasNextPage: $hasNextPage,
            hasPreviousPage: $hasPreviousPage,
            startCursor: $startCursor,
            endCursor: $endCursor,
        );

        return new self($edges, $pageInfo, $totalCount);
    }
}
