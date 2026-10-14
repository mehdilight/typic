<?php

declare(strict_types=1);

namespace Typic\Server;

use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\Node;
use GraphQL\Validator\Rules\QueryDepth as BaseQueryDepth;

/**
 * Limits application queries without blocking explorer schema discovery.
 *
 * @internal
 */
final class QueryDepth extends BaseQueryDepth
{
    protected function nodeDepth(Node $node, int $depth = 0, int $maxDepth = 0): int
    {
        if ($node instanceof FieldNode && in_array($node->name->value, ['__schema', '__type'], true)) {
            return $maxDepth;
        }

        return parent::nodeDepth($node, $depth, $maxDepth);
    }
}
