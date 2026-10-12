<?php

declare(strict_types=1);

namespace Typic\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Typic\Subscription\PubSub\Redis\RedisClient;
use Typic\Subscription\PubSub\Redis\RedisConnectionException;

/**
 * Boots a real Workerman process and checks that a mutation sent over HTTP reaches a
 * subscriber connected over WebSocket on the same port.
 */
class LiveServerTest extends TestCase
{
    /** @var resource|null */
    private $process = null;

    private string $runtimeDir = '';

    private int $port = 0;

    private ?string $redisPrefix = null;

    protected function setUp(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            $this->markTestSkipped('Workerman needs the pcntl and posix extensions.');
        }
    }

    /**
     * @return array<string, array{bool, int}>
     */
    public static function backplanes(): array
    {
        return [
            'in-memory, one worker' => [false, 1],
            // Two worker processes: events must cross processes through Redis
            'redis, two workers' => [true, 2],
        ];
    }

    private function startServer(bool $redis, int $workers): void
    {
        if ($redis) {
            try {
                (new RedisClient())->command('PING');
            } catch (RedisConnectionException) {
                $this->markTestSkipped('Redis is not reachable on 127.0.0.1:6379.');
            }
            $this->redisPrefix = 'typic-test-' . bin2hex(random_bytes(4)) . ':';
        }

        $this->runtimeDir = sys_get_temp_dir() . '/typic-live-' . bin2hex(random_bytes(4));
        mkdir($this->runtimeDir);
        $this->port = $this->findFreePort();

        $output = $this->runtimeDir . '/output.log';
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/../Fixtures/Live/server.php', 'start'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $output, 'a'], 2 => ['file', $output, 'a']],
            $pipes,
            null,
            array_merge(getenv(), [
                'TYPIC_PORT' => (string) $this->port,
                'TYPIC_RUNTIME_DIR' => $this->runtimeDir,
                'TYPIC_REDIS_PREFIX' => $this->redisPrefix ?? '',
                'TYPIC_WORKERS' => (string) $workers,
            ]),
        );
        $this->assertIsResource($process);
        $this->process = $process;

        $this->waitForPort();
    }

    protected function tearDown(): void
    {
        if ($this->process !== null) {
            proc_terminate($this->process, SIGINT);
            proc_close($this->process);
            $this->process = null;
        }

        if ($this->runtimeDir !== '' && is_dir($this->runtimeDir)) {
            foreach ((array) glob($this->runtimeDir . '/*') as $file) {
                @unlink((string) $file);
            }
            @rmdir($this->runtimeDir);
        }
    }

    #[DataProvider('backplanes')]
    public function test_http_and_websocket_share_one_port_and_pubsub(bool $redis, int $workers): void
    {
        $this->startServer($redis, $workers);

        // Explorer page over HTTP
        $html = $this->http('GET', '/graphql', null, ['Accept: text/html']);
        $this->assertStringContainsString('</html>', $html);

        // Subscribe over WebSocket on the same port
        $ws = $this->openWebSocket('/graphql');
        $this->wsSend($ws, ['type' => 'connection_init']);
        $this->assertSame('connection_ack', $this->wsReceive($ws)['type']);

        $this->wsSend($ws, [
            'id' => 'sub_1',
            'type' => 'subscribe',
            'payload' => ['query' => 'subscription { messageSent { id text } }'],
        ]);
        // Messages on one connection are handled in order, so the pong confirms the subscription is active
        $this->wsSend($ws, ['type' => 'ping']);
        $this->assertSame('pong', $this->wsReceive($ws)['type']);
        if ($this->redisPrefix !== null) {
            // The Redis SUBSCRIBE is asynchronous; wait until Redis has registered it
            $this->waitForRedisSubscriber($this->redisPrefix . 'MESSAGE_SENT');
        }

        // Mutation over HTTP publishes to the WebSocket subscriber
        $response = json_decode($this->http(
            'POST',
            '/graphql',
            (string) json_encode(['query' => 'mutation { sendMessage(text: "hello") { id text } }']),
            ['Content-Type: application/json'],
        ), true);
        $this->assertSame('hello', $response['data']['sendMessage']['text']);

        $event = $this->wsReceive($ws);
        $this->assertSame('next', $event['type']);
        $this->assertSame('sub_1', $event['id']);
        $this->assertSame('hello', $event['payload']['data']['messageSent']['text']);

        fclose($ws);
    }

    private function waitForRedisSubscriber(string $channel): void
    {
        $redis = new RedisClient();
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $reply = $redis->command('PUBSUB', 'NUMSUB', $channel);
            if (is_array($reply) && ($reply[1] ?? 0) > 0) {
                return;
            }
            usleep(20_000);
        }

        $this->fail("No Redis subscriber on {$channel}");
    }

    private function findFreePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    private function waitForPort(): void
    {
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $socket = @stream_socket_client("tcp://127.0.0.1:{$this->port}", $errno, $errstr, 0.2);
            if ($socket !== false) {
                fclose($socket);
                return;
            }
            usleep(50_000);
        }

        $this->fail('Server did not start: ' . @file_get_contents($this->runtimeDir . '/output.log'));
    }

    /**
     * @param list<string> $headers
     */
    private function http(string $method, string $path, ?string $body, array $headers): string
    {
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body ?? '',
            'ignore_errors' => true,
            'timeout' => 5,
        ]]);

        return (string) file_get_contents("http://127.0.0.1:{$this->port}{$path}", false, $context);
    }

    /**
     * @return resource
     */
    private function openWebSocket(string $path)
    {
        $socket = stream_socket_client("tcp://127.0.0.1:{$this->port}", $errno, $errstr, 5);
        $this->assertIsResource($socket, "Could not connect: {$errstr}");
        stream_set_timeout($socket, 5);

        fwrite($socket, "GET {$path} HTTP/1.1\r\n"
            . "Host: 127.0.0.1:{$this->port}\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . 'Sec-WebSocket-Key: ' . base64_encode(random_bytes(16)) . "\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "Sec-WebSocket-Protocol: graphql-transport-ws\r\n\r\n");

        $handshake = '';
        while (!str_contains($handshake, "\r\n\r\n")) {
            $line = fgets($socket);
            $this->assertNotFalse($line, 'WebSocket handshake timed out');
            $handshake .= $line;
        }
        $this->assertStringStartsWith('HTTP/1.1 101', $handshake);
        $this->assertStringContainsStringIgnoringCase('Sec-WebSocket-Protocol: graphql-transport-ws', $handshake);

        return $socket;
    }

    /**
     * @param resource $socket
     * @param array<string, mixed> $message
     */
    private function wsSend($socket, array $message): void
    {
        $payload = (string) json_encode($message);
        $length = strlen($payload);
        $header = chr(0x81) . ($length < 126
            ? chr(0x80 | $length)
            : chr(0x80 | 126) . pack('n', $length));
        $mask = random_bytes(4);
        $masked = $payload ^ substr(str_repeat($mask, intdiv($length, 4) + 1), 0, $length);

        fwrite($socket, $header . $mask . $masked);
    }

    /**
     * @param resource $socket
     * @return array<string, mixed>
     */
    private function wsReceive($socket): array
    {
        $header = $this->readExactly($socket, 2);
        $length = ord($header[1]) & 0x7F;
        if ($length === 126) {
            $length = $this->unpackLength('n', $this->readExactly($socket, 2));
        } elseif ($length === 127) {
            $length = $this->unpackLength('J', $this->readExactly($socket, 8));
        }

        $decoded = json_decode($this->readExactly($socket, $length), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function unpackLength(string $format, string $bytes): int
    {
        $unpacked = unpack($format, $bytes);
        $this->assertIsArray($unpacked);
        $this->assertIsInt($unpacked[1]);

        return $unpacked[1];
    }

    /**
     * @param resource $socket
     */
    private function readExactly($socket, int $length): string
    {
        $data = stream_get_contents($socket, $length);
        if ($data === false || strlen($data) !== $length) {
            $this->fail('Timed out waiting for a WebSocket frame');
        }

        return $data;
    }
}
