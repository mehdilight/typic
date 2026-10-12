<?php

declare(strict_types=1);

namespace Typic\Tests;

use PHPUnit\Framework\TestCase;
use Typic\Tests\Fixtures\Animals\AnimalResolver;
use Typic\Tests\Fixtures\Animals\Robot;
use Typic\Typic;

class SchemaCacheTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/typic-cache-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->cacheDir);
    }

    public function test_discovery_is_written_to_the_cache_directory(): void
    {
        $this->build();

        $cached = require $this->cacheDir . '/discovery.php';
        $this->assertSame([__DIR__ . '/Fixtures/Animals'], $cached['directories']);
        $this->assertContains(AnimalResolver::class, $cached['resolvers']);
        $this->assertContains(Robot::class, $cached['types']);
    }

    public function test_later_builds_use_the_cache_instead_of_scanning(): void
    {
        $this->build();

        // Pretend the scan found nothing: the next build must trust the cache
        $file = $this->cacheDir . '/discovery.php';
        $cached = require $file;
        $cached['resolvers'] = [];
        file_put_contents($file, '<?php return ' . var_export($cached, true) . ';');

        $result = $this->build()->execute('{ __schema { queryType { fields { name } } } }');
        $this->assertSame([['name' => '_health']], $result['data']['__schema']['queryType']['fields']);
    }

    public function test_changing_the_scanned_directories_scans_again(): void
    {
        $this->build();
        $file = $this->cacheDir . '/discovery.php';
        $cached = require $file;
        $cached['directories'] = ['/somewhere/else'];
        $cached['resolvers'] = [];
        file_put_contents($file, '<?php return ' . var_export($cached, true) . ';');

        $result = $this->build()->execute('{ firstNamed { name } }');
        $this->assertSame(['data' => ['firstNamed' => ['name' => 'RX-7']]], $result);
    }

    private function build(): \Typic\Server\TypicServer
    {
        return Typic::builder()
            ->scanDirectories([__DIR__ . '/Fixtures/Animals'])
            ->withCache($this->cacheDir)
            ->build();
    }
}
