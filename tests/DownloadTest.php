<?php

namespace Downloader\Downloader\Tests;

use Downloader\Downloader;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use PHPUnit\Framework\TestCase;

class DownloadTest extends TestCase
{
    private MockHandler $mock;
    private Client $httpClient;
    private vfsStreamDirectory $root;
    private string $rootPath;
    private Logger $logger;

    private function getFixtureFullPath(string $fixtureName): string
    {
        $parts = [__DIR__, 'fixtures', $fixtureName];
        $path = realpath(implode('/', $parts));
        return is_string($path) ? $path : '';
    }

    private function addMockAnswer(string $fixturePath): void
    {
        $expectedPath = $this->getFixtureFullPath($fixturePath);
        $expectedData = file_get_contents($expectedPath);

        $mockResponse = new Response(200, [], is_string($expectedData) ? $expectedData : '');
        $this->mock->append($mockResponse);
    }

    public function setUp(): void
    {
        $this->mock = new MockHandler([]);
        $this->httpClient = new Client(['handler' => HandlerStack::create($this->mock)]);
        $this->rootPath = vfsStream::url('home');
        $this->root = vfsStream::setup('home');
        $this->logger = new Logger('test');
        if (getenv('mode') === 'DEBUG') {
            $this->logger->pushHandler(new StreamHandler("php://output", Logger::DEBUG));
        }
    }

    public function testDownload(): void
    {
        $url = 'https://ru.hexlet.io/courses';

        $expectedFilename = 'ru-hexlet-io-courses';

        $this->addMockAnswer('html/with-resources.html');
        $this->addMockAnswer('resources/application.css');
        $this->addMockAnswer('html/with-resources.html');
        $this->addMockAnswer('resources/php.png');
        $this->addMockAnswer('resources/runtime.js');
        $expectedPath = $this->getFixtureFullPath('results/with-resources.html');
        $expectedData = file_get_contents($expectedPath);

        $result = Downloader\downloadPage($url, $this->rootPath, $this->httpClient, $this->logger);

        $this->assertEquals("{$this->rootPath}/{$expectedFilename}.html", $result);

        $this->assertTrue($this->root->hasChild("{$expectedFilename}.html"));
        $actualData = file_get_contents("{$this->rootPath}/{$expectedFilename}.html");
        $this->assertEquals($expectedData, $actualData);

        $this->assertTrue($this->root->hasChild("{$expectedFilename}_files"));

        $resources = [
            ['https://ru.hexlet.io/assets/application.css', 'resources/application.css'],
            ['https://ru.hexlet.io/courses', 'html/with-resources.html'],
            ['https://ru.hexlet.io/assets/professions/php.png', 'resources/php.png'],
            ['https://ru.hexlet.io/packs/js/runtime.js', 'resources/runtime.js'],
        ];
        collect($resources)->each(function ($resource) use ($expectedFilename): void {
            [$link, $fixture] = $resource;
            $assetPath = Downloader\prepareFileName($link);
            $assetFixture = $this->getFixtureFullPath($fixture);
            $assetData = file_get_contents($assetFixture);
            $this->assertTrue(
                $this->root->hasChild("{$expectedFilename}_files/{$assetPath}"),
                "{$link} -> {$expectedFilename}_files/{$assetPath} doesnt exist"
            );
            $actualData = file_get_contents("{$this->rootPath}/{$expectedFilename}_files/{$assetPath}");
            $this->assertEquals($assetData, $actualData, "{$assetPath} doesnt match contents");
        });
    }

    /**
     * @dataProvider networkErrorsProvider
     */
    public function testNetworkError(int $code): void
    {
        $this->logger->info("testing network error when got response with code {$code}");
        $this->mock->append(new Response($code));

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('https://ru.hexlet.io/courses');
        Downloader\downloadPage('https://ru.hexlet.io/courses', $this->rootPath, $this->httpClient, $this->logger);
    }

    /**
     * @dataProvider networkErrorsProvider
     */
    public function testResourceNetworkError(int $code): void
    {
        $this->logger->info("testing resource network error when got response with code {$code}");
        $this->addMockAnswer('html/with-resources.html');
        $this->mock->append(new Response($code));

        $this->expectException(\Error::class);
        $this->expectExceptionMessage("https://ru.hexlet.io/assets/application.css");
        Downloader\downloadPage('https://ru.hexlet.io/courses', $this->rootPath, $this->httpClient, $this->logger);
    }

    public function networkErrorsProvider(): array
    {
        return [[400], [401], [402], [403], [404], [500], [503], [301], [302], [201]];
    }

    public function testSaveError(): void
    {
        $this->addMockAnswer('html/with-resources.html');
        $this->addMockAnswer('resources/php.png');
        $this->addMockAnswer('resources/application.css');
        $this->addMockAnswer('html/with-resources.html');
        $this->addMockAnswer('resources/runtime.js');

        $dirName = "{$this->rootPath}/ru-hexlet-io-courses_files";
        mkdir($dirName, 0111);

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('ru-hexlet-io-courses_files is not writable');
        Downloader\downloadPage('https://ru.hexlet.io/courses', $this->rootPath, $this->httpClient, $this->logger);
    }

    public function testDirError(): void
    {
        $this->addMockAnswer('html/empty.html');

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('abc does not exist');
        Downloader\downloadPage('https://ru.hexlet.io/courses', 'abc', $this->httpClient, $this->logger);
    }

    public function testWritableError(): void
    {
        $this->addMockAnswer('html/empty.html');
        $dirName = "{$this->rootPath}/x";
        mkdir($dirName, 0111);

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('is not writable');
        Downloader\downloadPage('https://ru.hexlet.io/courses', $dirName, $this->httpClient, $this->logger);
    }
}
