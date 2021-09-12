<?php

namespace Downloader\Downloader;

use DiDom\Document;
use Error;
use GuzzleHttp\Client;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

/**
 * @param  string  $url
 * @param  string  $outputDir
 * @param string|Client $clientClass
 * @param  Logger|null  $logger
 * @return string
 */
function downloadPage(string $url, string $outputDir, $clientClass, Logger $logger = null): string
{
    if (!is_dir($outputDir)) {
        throw new Error("output directory {$outputDir} does not exist");
    }
    if (!is_writable($outputDir)) {
        throw new Error("output directory {$outputDir} is not writable");
    }

    $logger = $logger ?? new Logger('empty');

    $httpClient = is_string($clientClass)
        ? new $clientClass()
        : $clientClass;

    $logger->info("getting data from {$url}");
    $data = getUrl($url, $httpClient, $logger);
    $logger->info("start parsing resource list");
    $resources = gatherResources($data, $url);
    $paths = collect($resources)->map(fn($data) => $data[0])->toArray();
    $logger->info("parsed resource list:", $paths);
    $logger->info("start resources handling");
    $savedFiles = downloadResources($url, $paths, $outputDir, $logger, $httpClient);
    $logger->info("completed resources handling");
    $logger->info("replace resource paths in document");
    replaceResourcePaths($resources, $savedFiles, $outputDir, $logger);
    $fileName = saveFile($data->html(), $url, $outputDir, $logger);
    $logger->info("return {$fileName}");
    return $fileName;
}

function gatherResources(Document $dom, string $baseUri): array
{
    $elements = $dom->find('img, link, script');
    $results = collect($elements)
        ->map(fn($element) => [
            $element->hasAttribute('src') ? $element->getAttribute('src') : $element->getAttribute('href'),
            $element
        ]);
    $normalizedBase = normalizeUrl($baseUri, false);
    return $results
        ->filter(fn($pathData) => needToDownload($pathData, $normalizedBase))
        ->toArray();
}

/**
 * @param  string  $url
 * @param  array  $resources
 * @param  string  $outputDir
 * @param  Logger  $logger
 * @param Client $httpClient
 * @return array
 */
function downloadResources(string $url, array $resources, string $outputDir, Logger $logger, $httpClient): array
{
    $filename = prepareFileName($url, '');
    $subDir = "{$outputDir}/{$filename}_files";
    $logger->info("resources dir: {$subDir}");
    $normalizedBase = normalizeUrl($url, false);
    $host = parse_url($url, PHP_URL_HOST);
    $paths = collect($resources)
        ->map(fn($path) => normalizeUrl($path))
        ->map(fn($path) => preg_replace("~https?://{$host}~", '', $path))
        ->map(fn($path) => ltrim($path, '/'))
        ->map(fn($path) => "{$normalizedBase}/{$path}");
    $logger->info('got normalized resources paths', $paths->toArray());
    $logger->info("start resources download");
    $savedFiles = $paths->map(fn($path) => getResource($path, $subDir, $logger, $httpClient));
    $logger->info("completed resources download");
    return $savedFiles->toArray();
}

function needToDownload(array $pathData, string $baseUri): bool
{
    [$uri] = $pathData;
    if (!$uri) {
        return false;
    }
    $normalizedUri = normalizeUrl($uri, false);
    if ($normalizedUri === trim($uri, '/')) {
        return true;
    }
    return parse_url($baseUri, PHP_URL_HOST) == parse_url($normalizedUri, PHP_URL_HOST);
}

function replaceResourcePaths(array $links, array $files, string $outputDir, Logger $logger): void
{
    $relativeFiles = collect($files)
        ->map(fn($filePath) => str_replace("{$outputDir}/", '', $filePath))
        ->toArray();
    $logger->info('got relative files paths', $relativeFiles);
    $replacedLinks = collect($links)->map(function ($link, $key) use ($relativeFiles) {
        if ($link[1]->hasAttribute('src')) {
            $link[1]->src = $relativeFiles[$key];
        } else {
            $link[1]->href = $relativeFiles[$key];
        }
        return $link[1];
    })->toArray();
    $logger->info('prepared replaced tags', $replacedLinks);
}

/**
 * @param  string  $url
 * @param Client $httpClient
 * @param  Logger  $logger
 * @return Document
 */
function getUrl(string $url, $httpClient, Logger $logger): Document
{
    $response = $httpClient->get($url, ['allow_redirects' => false, 'http_errors' => false]);
    if (is_object($response) && method_exists($response, 'getStatusCode')) {
        $code = $response->getStatusCode();
        $logger->info("{$url}: got response with code {$code}");
        if ($code !== 200) {
            throw new Error("received response with status code {$code} while accessing {$url}");
        }
    }
    $html = $response->getBody()->getContents();

    $dom = new Document();
    if ($html !== '') {
        $dom->loadHtml($html);
    }
    return $dom;
}

/**
 * @param  string  $url
 * @param  string  $path
 * @param  Logger  $logger
 * @param Client $httpClient
 * @return string
 */
function getResource(string $url, string $path, Logger $logger, $httpClient): string
{
    if (!is_dir($path)) {
        $logger->info("create dir {$path}");
        mkdir($path);
    }
    if (!is_writable($path)) {
        throw new Error("{$path} is not writable");
    }
    $fileName = prepareFileName($url);
    $filePath = "{$path}/{$fileName}";
    $response = $httpClient->request(
        'get',
        $url,
        ['sink' => $filePath, 'allow_redirects' => false, 'http_errors' => false]
    );
    if (is_object($response) && method_exists($response, 'getStatusCode')) {
        $code = $response->getStatusCode();
        $logger->info("{$url}: got response with code {$code}");
        if ($code !== 200) {
            throw new Error("received response with status code {$code} while accessing {$url}");
        }
    }
    $logger->info("downloaded {$url} to {$filePath}");
    return $filePath;
}

function normalizeUrl(string $url, bool $usePath = true): string
{
    $url = trim($url, '/');
    $parsedUrl = parse_url($url);
    if ($parsedUrl === false || !array_key_exists('scheme', $parsedUrl) || !array_key_exists('host', $parsedUrl)) {
        return $url;
    }
    $path = "{$parsedUrl['scheme']}://{$parsedUrl['host']}";
    $path = isset($parsedUrl['path']) && $parsedUrl['path'] && $usePath
        ? "{$path}{$parsedUrl['path']}"
        : $path;
    return strtolower($path ?? '');
}

function saveFile(string $data, string $url, string $outputDir, Logger $logger): string
{
    $logger->info("saving html\n{$data}");
    $fileName = prepareFileName($url);
    $filePath = "{$outputDir}/{$fileName}";
    $logger->info("saving file to {$filePath}");
    file_put_contents($filePath, $data);
    return $filePath;
}

function prepareFileName(string $url, string $defaultExt = 'html', bool $usePath = true): string
{
    $schema = parse_url($url, PHP_URL_SCHEME);
    $path = parse_url($url, PHP_URL_PATH);
    $ext = pathinfo($path ?: '', PATHINFO_EXTENSION);
    $ext = $ext ?: $defaultExt;
    $replaceExt = $ext ? "~\.{$ext}~" : '~~';
    $name = preg_replace(
        ["~$schema://~", $replaceExt, '~[^\d\w]~'],
        ['', '', '-'],
        normalizeUrl($url, $usePath)
    );
    $ext = $ext ? ".{$ext}" : '';
    return "{$name}{$ext}";
}
