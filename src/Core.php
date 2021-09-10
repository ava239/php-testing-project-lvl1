<?php

namespace Ava239\Page\Loader;

use DiDom\Document;
use Error;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use Monolog\Logger;

class Core
{
    private string $outputDir;
    private string $baseUri;
    private Client $httpClient;
    private Logger $logger;

    public function download(string $url, string $outputDir, Client $httpClient, Logger $logger): string
    {
        if (!is_dir($outputDir)) {
            throw new Error("output directory {$outputDir} does not exist");
        }
        if (!is_writable($outputDir)) {
            throw new Error("output directory {$outputDir} is not writable");
        }
        $this->outputDir = $outputDir;
        $this->httpClient = $httpClient;
        $this->logger = $logger;

        $this->logger->info("set base URI {$url}");
        $this->setBaseUri($url);

        $this->logger->info("getting data from base URI");
        $data = $this->getUrl($url);
        $this->logger->info("start parsing resource list");
        $resources = $this->getResources($data);
        $this->logger->info("parsed resource list:", $resources);
        $this->logger->info("start resources handling");
        $savedFiles = $this->downloadResources($url, $resources);
        $this->logger->info("completed resources handling");
        $this->logger->info("replace resource paths in document");
        $newData = $this->replaceResourcePaths($data, $resources, $savedFiles);
        $fileName = $this->saveFile($newData, $url);
        $this->logger->info("return {$fileName}");
        return $fileName;
    }

    public function setBaseUri(string $uri): void
    {
        $this->baseUri = $uri;
    }

    public function getResources(string $html): array
    {
        $dom = new Document();
        if ($html !== '') {
            $dom->loadHtml($html);
        }
        $images = $dom->find('img');
        $imageSrcs = collect($images)->map(fn ($image) => $image->getAttribute('src'));
        $links = $dom->find('link');
        $linkSrcs = collect($links)->map(fn ($link) => $link->getAttribute('href'));
        $scripts = $dom->find('script');
        $scriptSrcs = collect($scripts)->map(fn ($script) => $script->getAttribute('src'));
        return $imageSrcs
            ->merge($linkSrcs)
            ->merge($scriptSrcs)
            ->filter()
            ->filter(fn ($path) => $this->needToDownload($path))
            ->values()
            ->toArray();
    }

    public function downloadResources(string $url, array $resources): array
    {
        $subDir = "{$this->outputDir}/{$this->prepareFileName($url, '')}_files";
        $this->logger->info("resources dir: {$subDir}");
        $normalizedBase = $this->normalizeUrl($this->baseUri, false);
        $paths = collect($resources)
            ->map(fn ($path) => str_replace($normalizedBase, '', $this->normalizeUrl($path)))
            ->map(fn ($path) => trim($path, '/'))
            ->map(fn ($path) => "{$normalizedBase}/{$path}");
        $this->logger->info('got normalized resources paths', $paths->toArray());
        $this->logger->info("start resources download");
        $savedFiles = $paths->map(fn ($path) => $this->getResource($path, $subDir));
        $this->logger->info("completed resources download");
        return $savedFiles->toArray();
    }

    public function needToDownload(string $uri): bool
    {
        $normalizedUri = $this->normalizeUrl($uri, false);
        if ($normalizedUri === trim($uri, '/')) {
            return true;
        }
        $normalizedBase = $this->normalizeUrl($this->baseUri, false);
        return $normalizedBase === $normalizedUri;
    }

    public function replaceResourcePaths(string $html, array $links, array $files): string
    {
        $relativeFiles = collect($files)
            ->map(fn ($filePath) => str_replace("{$this->outputDir}/", '', $filePath))
            ->toArray();
        $this->logger->info('got relative files paths', $relativeFiles);
        return str_replace($links, $relativeFiles, $html);
    }

    public function getUrl(string $url): string
    {
        $response = $this->httpClient->get($url, ['allow_redirects' => false]);
        $code = $response->getStatusCode();
        $this->logger->info("{$url}: got response with code {$code}");
        if ($code !== 200) {
            throw new TransferException("received response with status code {$code} while accessing {$url}");
        }
        return $response->getBody()->getContents();
    }

    public function getResource(string $url, string $path): string
    {
        if (!is_dir($path)) {
            $this->logger->info("create dir {$path}");
            mkdir($path);
        }
        if (!is_writable($path)) {
            throw new Error("{$path} is not writable");
        }
        $filePath = "{$path}/{$this->prepareFileName($url)}";
        $response = $this->httpClient->request('get', $url, ['sink' => $filePath, 'allow_redirects' => false]);
        $code = $response->getStatusCode();
        $this->logger->info("{$url}: got response with code {$code}");
        if ($code !== 200) {
            throw new TransferException("received response with status code {$code} while accessing {$url}");
        }
        $this->logger->info("downloaded {$url} to {$filePath}");
        return $filePath;
    }

    private function normalizeUrl(string $url, bool $usePath = true): string
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

    private function saveFile(string $data, string $url): string
    {
        $fileName = $this->prepareFileName($url);
        $filePath = "{$this->outputDir}/{$fileName}";
        $this->logger->info("saving file to {$filePath}");
        file_put_contents($filePath, $data);
        return $filePath;
    }

    public function prepareFileName(string $url, string $defaultExt = 'html', bool $usePath = true): string
    {
        $schema = parse_url($url, PHP_URL_SCHEME);
        $path = parse_url($url, PHP_URL_PATH);
        $ext = pathinfo($path ?: '', PATHINFO_EXTENSION);
        $ext = $ext ?: $defaultExt;
        $replaceExt = $ext ? "~\.{$ext}~" : '~~';
        $name = preg_replace(
            ["~$schema://~", $replaceExt, '~[^\d\w]~'],
            ['', '', '-'],
            $this->normalizeUrl($url, $usePath)
        );
        $ext = $ext ? ".{$ext}" : '';
        return "{$name}{$ext}";
    }
}
