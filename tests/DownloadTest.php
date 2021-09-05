<?php

namespace Ava239\Page\Loader\Tests;

use Ava239\Page\Loader\Core;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use Faker\Generator;
use Faker\Factory;
use PHPUnit\Framework\TestCase;

class DownloadTest extends TestCase
{
    private MockHandler $mock;
    private Client $httpClient;
    private vfsStreamDirectory $root;
    private Generator $faker;
    private Core $loader;

    public function getFixtureFullPath(string $fixtureName): string
    {
        $parts = [__DIR__, 'fixtures', $fixtureName];
        $path = realpath(implode('/', $parts));
        return $path ?: '';
    }

    public function setUp(): void
    {
        $this->faker = Factory::create();
        $this->mock = new MockHandler([]);
        $this->httpClient = new Client(['handler' => HandlerStack::create($this->mock)]);
        $this->root = vfsStream::setup('home');
        $this->loader = new Core();
    }

    public function testSimpleDownload(): void
    {
        $directoryPath = vfsStream::url('home');
        $url = $this->faker->url();
        $expectedPath = $this->getFixtureFullPath('simple.html');
        $expectedData = file_get_contents($expectedPath);
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $expectedFilename = preg_replace(["~$scheme://~", '~[^\d\w]~'], ['', '-'], $url) . '.html';

        $mockResponse = new Response(200, [], $expectedData ?: '');
        $this->mock->append($mockResponse);

        $result = $this->loader->download($url, $directoryPath, $this->httpClient);

        $this->assertEquals("{$directoryPath}/{$expectedFilename}", $result);

        $this->assertTrue($this->root->hasChild("$expectedFilename"));

        $actualData = file_get_contents("{$directoryPath}/$expectedFilename");
        $this->assertEquals($expectedData, $actualData);
    }
}
