<?php

declare(strict_types=1);

namespace Typic;

use Typic\Server\TypicBuilder;
use Typic\Server\TypicServer;

class Typic
{
    /**
     * Create a fluent TypicBuilder.
     */
    public static function builder(): TypicBuilder
    {
        return new TypicBuilder();
    }

    /**
     * Create a TypicServer with an options array.
     *
     * @param array{
     *     resolvers?: array<class-string|object>,
     *     types?: array<class-string>,
     *     scanDirectories?: list<string>,
     *     container?: \Psr\Container\ContainerInterface|null,
     *     sandbox?: bool,
     *     graphiql?: bool,
     *     debug?: bool,
     * } $options
     */
    public static function create(array $options = []): TypicServer
    {
        $builder = self::builder();

        if (isset($options['resolvers'])) {
            $builder->registerResolvers($options['resolvers']);
        }

        if (isset($options['types'])) {
            $builder->registerTypes($options['types']);
        }

        if (isset($options['scanDirectories'])) {
            $builder->scanDirectories($options['scanDirectories']);
        }

        if (isset($options['container'])) {
            $builder->withContainer($options['container']);
        }

        if (isset($options['sandbox'])) {
            $builder->withSandbox((bool) $options['sandbox']);
        } elseif (isset($options['graphiql'])) {
            $builder->withSandbox((bool) $options['graphiql']);
        }

        if (isset($options['debug'])) {
            $builder->withDebug((bool) $options['debug']);
        }

        return $builder->build();
    }
}
