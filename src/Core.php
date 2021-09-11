<?php

namespace Downloader\Downloader;

use DiDom\Document;
use Error;
use GuzzleHttp\Client;
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

    $logger->info("getting data from {$url}");
    $data = getUrl($url, $clientClass, $logger);
    $logger->info("start parsing resource list");
    $resources = getResources($data, $url);
    $logger->info("parsed resource list:", $resources);
    $logger->info("start resources handling");
    $savedFiles = downloadResources($url, $resources, $outputDir, $logger, $clientClass);
    $logger->info("completed resources handling");
    $logger->info("replace resource paths in document");
    $newData = replaceResourcePaths($data, $resources, $savedFiles, $outputDir, $logger);
    $fileName = saveFile($newData, $url, $outputDir, $logger);
    $logger->info("return {$fileName}");
    return $fileName;
}

function getResources(string $html, string $baseUri): array
{
    $dom = new Document();
    if ($html !== '') {
        $dom->loadHtml($html);
    }
    $images = $dom->find('img');
    $imageSrcs = collect($images)->map(fn($image) => $image->getAttribute('src'));
    $links = $dom->find('link');
    $linkSrcs = collect($links)->map(fn($link) => $link->getAttribute('href'));
    $scripts = $dom->find('script');
    $scriptSrcs = collect($scripts)->map(fn($script) => $script->getAttribute('src'));
    return $imageSrcs
        ->merge($linkSrcs)
        ->merge($scriptSrcs)
        ->filter()
        ->filter(fn($path) => needToDownload($path, $baseUri))
        ->values()
        ->toArray();
}

/**
 * @param  string  $url
 * @param  array  $resources
 * @param  string  $outputDir
 * @param  Logger  $logger
 * @param string|Client $clientClass
 * @return array
 */
function downloadResources(string $url, array $resources, string $outputDir, Logger $logger, $clientClass): array
{
    $filename = prepareFileName($url, '');
    $subDir = "{$outputDir}/{$filename}_files";
    $logger->info("resources dir: {$subDir}");
    $normalizedBase = normalizeUrl($url, false);
    $paths = collect($resources)
        ->map(fn($path) => str_replace($normalizedBase, '', normalizeUrl($path)))
        ->map(fn($path) => trim($path, '/'))
        ->map(fn($path) => "{$normalizedBase}/{$path}");
    $logger->info('got normalized resources paths', $paths->toArray());
    $logger->info("start resources download");
    $savedFiles = $paths->map(fn($path) => getResource($path, $subDir, $logger, $clientClass));
    $logger->info("completed resources download");
    return $savedFiles->toArray();
}

function needToDownload(string $uri, string $baseUri): bool
{
    $normalizedUri = normalizeUrl($uri, false);
    if ($normalizedUri === trim($uri, '/')) {
        return true;
    }
    $normalizedBase = normalizeUrl($baseUri, false);
    return $normalizedBase === $normalizedUri;
}

function replaceResourcePaths(string $html, array $links, array $files, string $outputDir, Logger $logger): string
{
    $relativeFiles = collect($files)
        ->map(fn($filePath) => str_replace("{$outputDir}/", '', $filePath))
        ->toArray();
    $logger->info('got relative files paths', $relativeFiles);
    return str_replace($links, $relativeFiles, $html);
}

/**
 * @param  string  $url
 * @param  string|Client  $clientClass
 * @param  Logger  $logger
 * @return string
 */
function getUrl(string $url, $clientClass, Logger $logger): string
{
    $httpClient = is_string($clientClass)
        ? new $clientClass()
        : $clientClass;

    $response = $httpClient->get($url, ['allow_redirects' => false, 'http_errors' => false]);
    if (is_object($response) && method_exists($response, 'getStatusCode')) {
        $code = $response->getStatusCode();
        $logger->info("{$url}: got response with code {$code}");
        if ($code !== 200) {
            throw new Error("received response with status code {$code} while accessing {$url}");
        }
    }
    return $response->getBody()->getContents();
}

/**
 * @param  string  $url
 * @param  string  $path
 * @param  Logger  $logger
 * @param string|Client $clientClass
 * @return string
 */
function getResource(string $url, string $path, Logger $logger, $clientClass): string
{
    $httpClient = is_string($clientClass)
        ? new $clientClass()
        : $clientClass;

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
