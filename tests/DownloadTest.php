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
    private string $rootPath;
    private Generator $faker;
    private Core $loader;

    private function getFixtureFullPath(string $fixtureName): string
    {
        $parts = [__DIR__, 'fixtures', $fixtureName];
        $path = realpath(implode('/', $parts));
        return $path ?: '';
    }

    private function addMock(string $path): string
    {
        $expectedPath = $this->getFixtureFullPath($path);
        $expectedData = file_get_contents($expectedPath);

        $mockResponse = new Response(200, [], $expectedData ?: '');
        $this->mock->append($mockResponse);
        return $expectedData ?: '';
    }

    public function setUp(): void
    {
        $this->faker = Factory::create();
        $this->mock = new MockHandler([]);
        $this->httpClient = new Client(['handler' => HandlerStack::create($this->mock)]);
        $this->root = vfsStream::setup('home');
        $this->rootPath = vfsStream::url('home');
        $this->loader = new Core();
    }

    public function testPrepareFilename(): void
    {
        $this->assertEquals(
            'ru-hexlet-io-courses.html',
            $this->loader->prepareFileName('https://ru.hexlet.io/courses')
        );
        $this->assertEquals(
            'ru-hexlet-io-courses.html',
            $this->loader->prepareFileName('https://ru.hexlet.io/courses/')
        );
        $this->assertEquals(
            'ru-hexlet-io-courses',
            $this->loader->prepareFileName('https://ru.hexlet.io/courses', '')
        );
        $this->assertEquals(
            'ru-hexlet-io-assets-professions-php.png',
            $this->loader->prepareFileName('https://ru.hexlet.io/assets/professions/php.png')
        );
    }

    public function testSimpleDownload(): void
    {
        $url = $this->faker->url();

        $expectedFilename = $this->loader->prepareFileName($url);

        $expectedData = $this->addMock('html/simple.html');

        $result = $this->loader->download($url, $this->rootPath, $this->httpClient);

        $this->assertEquals("{$this->rootPath}/{$expectedFilename}", $result);
        $actualData = file_get_contents("{$this->rootPath}/{$expectedFilename}");
        $this->assertEquals($expectedData, $actualData);

        $this->assertTrue($this->root->hasChild("$expectedFilename"));
    }

    public function testImagesDownload(): void
    {
        $url = 'https://ru.hexlet.io/courses';

        $expectedFilename = $this->loader->prepareFileName($url, '');

        $this->addMock('html/with-images.html');
        $this->addMock('resources/php.png');
        $expectedPath = $this->getFixtureFullPath('results/with-images.html');
        $expectedData = file_get_contents($expectedPath);

        $result = $this->loader->download($url, $this->rootPath, $this->httpClient);

        $this->assertEquals("{$this->rootPath}/{$expectedFilename}.html", $result);

        $this->assertTrue($this->root->hasChild("{$expectedFilename}.html"));
        $actualData = file_get_contents("{$this->rootPath}/{$expectedFilename}.html");
        $this->assertEquals($expectedData, $actualData);

        $this->assertTrue($this->root->hasChild("{$expectedFilename}_files"));

        $imgPath = $this->loader->prepareFileName('https://ru.hexlet.io/assets/professions/php.png');
        $imgFixture = $this->getFixtureFullPath('resources/php.png');
        $imgData = file_get_contents($imgFixture);
        $this->assertTrue($this->root->hasChild("{$expectedFilename}_files/{$imgPath}"));
        $actualData = file_get_contents("{$this->rootPath}/{$expectedFilename}_files/{$imgPath}");
        $this->assertEquals($imgData, $actualData);
    }
}
