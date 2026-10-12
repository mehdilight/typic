<?php

declare(strict_types=1);

namespace Typic\Explorer;

enum ExplorerType: string
{
    case GraphiQL = 'graphiql';
    case Altair = 'altair';
    case Apollo = 'apollo';
    case Sandbox = 'sandbox';

    public function createRenderer(): ExplorerInterface
    {
        return match ($this) {
            self::GraphiQL => new GraphiQLRenderer(),
            self::Altair => new AltairRenderer(),
            self::Apollo, self::Sandbox => new ApolloSandboxRenderer(),
        };
    }
}
