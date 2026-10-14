<?php

declare(strict_types=1);

namespace Typic\Tests;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Typic\Server\TypicServer;
use Typic\Subscription\Driver\Workerman\WorkermanDriver;
use Typic\Tests\Support\Violations;
use Typic\Tests\UploadFixtures\UploadResolver;
use Typic\Typic;
use Workerman\Protocols\Http\Request as WorkermanRequest;

class UploadTest extends TestCase
{
    // A 1x1 transparent PNG
    private const PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\rIDATx\x9cc\x00\x01\x00\x00\x05\x00\x01\x0d\n-\xb4\x00\x00\x00\x00IEND\xaeB`\x82";

    public function test_a_single_file(): void
    {
        $response = $this->send(
            ['query' => 'mutation ($file: Upload!) { upload(file: $file) }', 'variables' => ['file' => null]],
            ['0' => ['variables.file']],
            ['0' => $this->file('hello.txt', 'Hello!')],
        );

        $this->assertSame(['data' => ['upload' => 'hello.txt 6 Hello!']], $this->json($response));
    }

    public function test_lists_and_input_fields(): void
    {
        $many = $this->send(
            ['query' => 'mutation ($files: [Upload!]!) { uploadMany(files: $files) }', 'variables' => ['files' => [null, null]]],
            ['0' => ['variables.files.0'], '1' => ['variables.files.1']],
            ['0' => $this->file('a.txt', 'a'), '1' => $this->file('b.txt', 'b')],
        );
        $this->assertSame(['data' => ['uploadMany' => ['a.txt', 'b.txt']]], $this->json($many));

        $profile = $this->send(
            ['query' => 'mutation ($input: ProfileInput!) { updateProfile(input: $input) }', 'variables' => ['input' => ['name' => 'Mehdi', 'avatar' => null]]],
            ['0' => ['variables.input.avatar']],
            ['0' => $this->file('me.png', self::PNG)],
        );
        $this->assertSame(['data' => ['updateProfile' => 'Mehdi:me.png']], $this->json($profile));
    }

    public function test_one_file_can_fill_several_variables(): void
    {
        $response = $this->send(
            ['query' => 'mutation ($files: [Upload!]!) { uploadMany(files: $files) }', 'variables' => ['files' => [null, null]]],
            ['0' => ['variables.files.0', 'variables.files.1']],
            ['0' => $this->file('same.txt', 'x')],
        );

        $this->assertSame(['data' => ['uploadMany' => ['same.txt', 'same.txt']]], $this->json($response));
    }

    public function test_file_rules_check_size_and_the_real_content_type(): void
    {
        $query = ['query' => 'mutation ($image: Upload!) { uploadImage(image: $image) }', 'variables' => ['image' => null]];
        $map = ['0' => ['variables.image']];

        $ok = $this->send($query, $map, ['0' => $this->file('dot.png', self::PNG, 'image/png')]);
        $this->assertSame(['data' => ['uploadImage' => 'dot.png']], $this->json($ok));

        // Claims to be a PNG, but the content says otherwise
        $fake = $this->json($this->send($query, $map, ['0' => $this->file('fake.png', '<?php echo 1;', 'image/png')]));
        $this->assertSame(['image' => ['must be a file of type image/*']], Violations::messages($fake['errors'][0]));

        $big = $this->json($this->send($query, $map, ['0' => $this->file('big.png', self::PNG . str_repeat("\0", 2000), 'image/png')]));
        $this->assertSame(['image' => ['must be at most 1 KB']], Violations::messages($big['errors'][0]));
    }

    public function test_multipart_requests_need_a_preflight_header(): void
    {
        $response = $this->send(
            ['query' => 'mutation ($file: Upload!) { upload(file: $file) }', 'variables' => ['file' => null]],
            ['0' => ['variables.file']],
            ['0' => $this->file('x.txt', 'x')],
            preflightHeader: false,
        );

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('GraphQL-Require-Preflight', $this->json($response)['errors'][0]['message']);
    }

    public function test_form_posts_need_a_preflight_header_too(): void
    {
        $form = (new ServerRequest('POST', '/graphql', ['Content-Type' => 'application/x-www-form-urlencoded']))
            ->withParsedBody(['query' => 'mutation { upload(file: null) }']);

        $this->assertSame(400, $this->server()->handle($form)->getStatusCode());
    }

    public function test_csrf_protection_can_be_turned_off(): void
    {
        $server = Typic::builder()->registerResolvers([UploadResolver::class])->withCsrfProtection(false)->build();
        $form = (new ServerRequest('POST', '/graphql', ['Content-Type' => 'application/x-www-form-urlencoded']))
            ->withParsedBody(['query' => '{ ping }']);

        $this->assertSame(['data' => ['ping' => 'pong']], $this->json($server->handle($form)));
    }

    public function test_broken_multipart_requests_are_explained(): void
    {
        $missing = $this->send(['query' => 'mutation ($file: Upload!) { upload(file: $file) }', 'variables' => ['file' => null]], ['0' => ['variables.file']], []);
        $this->assertSame(400, $missing->getStatusCode());
        $this->assertSame('File "0" is listed in the map but was not uploaded.', $this->json($missing)['errors'][0]['message']);

        $batched = $this->send([['query' => '{ ping }'], ['query' => '{ ping }']], [], []);
        $this->assertSame('Batched operations are not supported.', $this->json($batched)['errors'][0]['message']);

        $badPath = $this->send(['query' => '{ ping }'], ['0' => ['query']], ['0' => $this->file('x.txt', 'x')]);
        $this->assertSame('The map can only place files under "variables", got "query".', $this->json($badPath)['errors'][0]['message']);
    }

    public function test_upload_cannot_be_written_in_the_query(): void
    {
        $result = $this->server()->execute('mutation { upload(file: "not a file") }');

        $this->assertStringContainsString('Upload values must be sent as files in a multipart request', $result['errors'][0]['message']);
    }

    public function test_workerman_turns_multipart_bodies_into_uploaded_files(): void
    {
        $boundary = 'XyZ';
        $body = "--{$boundary}\r\nContent-Disposition: form-data; name=\"operations\"\r\n\r\n"
            . '{"query":"mutation ($file: Upload!) { upload(file: $file) }","variables":{"file":null}}' . "\r\n"
            . "--{$boundary}\r\nContent-Disposition: form-data; name=\"map\"\r\n\r\n" . '{"0":["variables.file"]}' . "\r\n"
            . "--{$boundary}\r\nContent-Disposition: form-data; name=\"0\"; filename=\"note.txt\"\r\nContent-Type: text/plain\r\n\r\nFrom Workerman\r\n"
            . "--{$boundary}--\r\n";
        $raw = "POST /graphql HTTP/1.1\r\nHost: localhost\r\nGraphQL-Require-Preflight: 1\r\n"
            . "Content-Type: multipart/form-data; boundary={$boundary}\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body;

        $driver = new WorkermanDriver();
        $driver->onRequest($this->server()->handle(...));
        $response = $driver->handleHttpRequest(new WorkermanRequest($raw));

        $this->assertSame(['data' => ['upload' => 'note.txt 14 From Workerman']], json_decode($response->rawBody(), true));
    }

    /**
     * @param array<mixed> $operations
     * @param array<array-key, list<string>> $map
     * @param array<array-key, UploadedFile> $files
     */
    private function send(array $operations, array $map, array $files, bool $preflightHeader = true): ResponseInterface
    {
        $request = (new ServerRequest('POST', '/graphql', ['Content-Type' => 'multipart/form-data; boundary=x']))
            ->withParsedBody(['operations' => json_encode($operations), 'map' => json_encode($map)])
            ->withUploadedFiles($files);
        if ($preflightHeader) {
            $request = $request->withHeader('GraphQL-Require-Preflight', '1');
        }

        return $this->server()->handle($request);
    }

    private function file(string $name, string $content, string $type = 'text/plain'): UploadedFile
    {
        return new UploadedFile(Stream::create($content), strlen($content), UPLOAD_ERR_OK, $name, $type);
    }

    public function test_malformed_maps_are_refused_before_execution(): void
    {
        foreach ([['0' => 'variables.file'], ['0' => [123]], ['0' => ['variables.missing']]] as $map) {
            $request = (new ServerRequest('POST', '/graphql', ['Content-Type' => 'multipart/form-data', 'GraphQL-Require-Preflight' => '1']))
                ->withParsedBody(['operations' => json_encode(['query' => 'mutation ($file: Upload!) { upload(file: $file) }', 'variables' => ['file' => null]]), 'map' => json_encode($map)])
                ->withUploadedFiles(['0' => $this->file('x.txt', 'x')]);
            $response = $this->server()->handle($request);
            $this->assertSame(400, $response->getStatusCode());
        }
    }

    private function server(): TypicServer
    {
        return Typic::builder()->registerResolvers([UploadResolver::class])->build();
    }

    /**
     * @return array<string, mixed>
     */
    private function json(ResponseInterface $response): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) $response->getBody(), true);
    }
}
