<?php

declare(strict_types=1);

namespace Typic\Explorer;

interface ExplorerInterface
{
    /**
     * Render the HTML string for the GraphQL explorer/playground.
     *
     * @param string $endpoint The GraphQL HTTP endpoint URL.
     * @param array<string, mixed> $options Explorer configuration options.
     * @return string Complete HTML document string.
     */
    public function render(string $endpoint = '/graphql', array $options = []): string;
}
