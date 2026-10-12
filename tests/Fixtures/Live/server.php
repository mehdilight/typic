<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Typic\Subscription\Driver\Workerman\WorkermanDriver;
use Typic\Subscription\PubSub\InMemoryPubSub;
use Typic\Subscription\PubSub\RedisPubSub;
use Typic\Tests\Fixtures\Live\BlockFlagged;
use Typic\Tests\Fixtures\Live\LiveMessage;
use Typic\Tests\Fixtures\Live\LiveResolver;
use Typic\Typic;
use Workerman\Worker;

$runtimeDir = (string) getenv('TYPIC_RUNTIME_DIR');
Worker::$pidFile = $runtimeDir . '/server.pid';
Worker::$logFile = $runtimeDir . '/server.log';
Worker::$statusFile = $runtimeDir . '/server.status';

$redisPrefix = getenv('TYPIC_REDIS_PREFIX');
$pubSub = is_string($redisPrefix) && $redisPrefix !== ''
    ? RedisPubSub::fromUrl('redis://127.0.0.1:6379', $redisPrefix)
    : new InMemoryPubSub();

Typic::builder()
    ->registerResolvers([LiveResolver::class])
    ->registerTypes([LiveMessage::class])
    ->withMiddleware(new BlockFlagged())
    ->withSubscriptions(
        driver: new WorkermanDriver(
            host: '127.0.0.1',
            port: (int) getenv('TYPIC_PORT'),
            workerCount: (int) (getenv('TYPIC_WORKERS') ?: 1),
        ),
        pubSub: $pubSub,
        options: new \Typic\Subscription\SubscriptionOptions(keepAlive: (float) (getenv('TYPIC_KEEPALIVE') ?: 30)),
    )
    ->build()
    ->serve();
