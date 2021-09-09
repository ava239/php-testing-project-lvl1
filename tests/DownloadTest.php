<?php

namespace Ava239\Page\Loader\Tests;

use Ava239\Page\Loader\Core;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use PHPUnit\Framework\TestCase;

class DownloadTest extends TestCase
{
    private MockHandler $mock;
    private Client $httpClient;
    private vfsStreamDirectory $root;
    private string $rootPath;
    private Core $loader;

    private function getFixtureFullPath(string $fixtureName): string
    {
        $parts = [__DIR__, 'fixtures', $fixtureName];
        $path = realpath(implode('/', $parts));
        return $path ?: '';
    }

    private function addMockAnswer(string $fixturePath): string
    {
        $expectedPath = $this->getFixtureFullPath($fixturePath);
        $expectedData = file_get_contents($expectedPath);

        $mockResponse = new Response(200, [], $expectedData ?: '');
        $this->mock->append($mockResponse);
        return $expectedData ?: '';
    }

    public function setUp(): void
    {
        $this->mock = new MockHandler([]);
        $this->httpClient = new Client(['handler' => HandlerStack::create($this->mock)]);
        $this->root = vfsStream::setup('home');
        $this->rootPath = vfsStream::url('home');
        $this->loader = new Core();
    }

    public function testDownload(): void
    {
        $url = 'https://ru.hexlet.io/courses';

        $expectedFilename = $this->loader->prepareFileName($url, '');

        $this->addMockAnswer('html/with-resources.html');
        $this->addMockAnswer('resources/php.png');
        $this->addMockAnswer('resources/application.css');
        $this->addMockAnswer('html/with-resources.html');
        $this->addMockAnswer('resources/runtime.js');
        $expectedPath = $this->getFixtureFullPath('results/with-resources.html');
        $expectedData = file_get_contents($expectedPath);

        $result = $this->loader->download($url, $this->rootPath, $this->httpClient);

        $this->assertEquals("{$this->rootPath}/{$expectedFilename}.html", $result);

        $this->assertTrue($this->root->hasChild("{$expectedFilename}.html"));
        $actualData = file_get_contents("{$this->rootPath}/{$expectedFilename}.html");
        $this->assertEquals($expectedData, $actualData);

        $this->assertTrue($this->root->hasChild("{$expectedFilename}_files"));

        $resources = [
            ['https://ru.hexlet.io/assets/professions/php.png', 'resources/php.png'],
            ['https://ru.hexlet.io/assets/application.css', 'resources/application.css'],
            ['https://ru.hexlet.io/courses', 'html/with-resources.html'],
            ['https://ru.hexlet.io/packs/js/runtime.js', 'resources/runtime.js'],
        ];
        foreach ($resources as [$link, $fixture]) {
            $asseetPath = $this->loader->prepareFileName($link);
            $assetFixture = $this->getFixtureFullPath($fixture);
            $assetData = file_get_contents($assetFixture);
            $this->assertTrue(
                $this->root->hasChild("{$expectedFilename}_files/{$asseetPath}"),
                "{$link} -> {$expectedFilename}_files/{$asseetPath} doesnt exist"
            );
            $actualData = file_get_contents("{$this->rootPath}/{$expectedFilename}_files/{$asseetPath}");
            $this->assertEquals($assetData, $actualData, "{$asseetPath} doesnt match contents");
        }
    }

    public function testNetworkError(): void
    {
        $this->addMockAnswer('html/with-resources.html');
        $this->mock->append(new Response(404));

        $this->expectException(\GuzzleHttp\Exception\RequestException::class);
        $this->expectExceptionMessage('https://ru.hexlet.io/assets/professions/php.png');
        $this->loader->download('https://ru.hexlet.io/courses', $this->rootPath, $this->httpClient);
    }

    public function testSaveError(): void
    {
        $this->addMockAnswer('html/empty.html');
        $expectedFilename = $this->loader->prepareFileName('https://ru.hexlet.io/courses', '');
        $dirName = "{$this->rootPath}/{$expectedFilename}_files";
        vfsStream::newDirectory($dirName, 0111);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('home/ru-hexlet-io-courses.html');
        $this->loader->download('https://ru.hexlet.io/courses', $this->rootPath, $this->httpClient);
    }
}
