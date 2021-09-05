<?php

namespace Ava239\Page\Loader;

use DiDom\Document;
use Exception;
use GuzzleHttp\Client;

class Core
{
    private string $outputDir;
    private Client $httpClient;

    public function download(string $url, string $outputDir, Client $httpClient = null): string
    {
        $realDirpath = realpath($outputDir);
        $this->outputDir = $realDirpath !== false
            ? $realDirpath
            : $outputDir;
        $this->httpClient = $httpClient ?? new Client();

        if (!is_dir($this->outputDir)) {
            mkdir($this->outputDir);
        }

        $data = $this->getUrl($url);
        $resources = $this->getResources($data);
        $savedFiles = $this->downloadResources($url, $resources);
        $newData = $this->replaceResourcePaths($data, $resources, $savedFiles);
        $fileName = $this->saveFile($newData, $url);

        return $fileName;
    }

    public function getResources(string $html): array
    {
        $dom = new Document();
        if ($html !== '') {
            $dom->loadHtml($html);
        }
        $images = $dom->find('img');
        return collect($images)->map(fn ($image) => $image->getAttribute('src'))->toArray();
    }

    public function downloadResources(string $url, array $resources): array
    {
        $subDir = "{$this->outputDir}/{$this->prepareFileName($url, '')}_files";
        $paths = collect($resources)->map(fn ($path) => "{$this->normalizeUrl($url, false)}{$path}");
        $savedFiles = $paths->map(fn ($path) => $this->getResource($path, $subDir));
        return $savedFiles->toArray();
    }

    public function replaceResourcePaths(string $html, array $links, array $files): string
    {
        $relativeFiles = collect($files)
            ->map(fn ($filePath) => str_replace("{$this->outputDir}/", '', $filePath))
            ->toArray();
        return str_replace($links, $relativeFiles, $html);
    }

    public function getUrl(string $url): string
    {
        return $this->httpClient->get($url)->getBody()->getContents();
    }

    public function getResource(string $url, string $path): string
    {
        if (!is_dir($path)) {
            mkdir($path);
        }
        $filePath = "{$path}/{$this->prepareFileName($url)}";
        $this->httpClient->request('get', $url, ['sink' => $filePath]);
        return $filePath;
    }

    private function normalizeUrl(string $url, bool $usePath = true): string
    {
        $parsedUrl = parse_url($url);
        if ($parsedUrl === false || !array_key_exists('scheme', $parsedUrl) || !array_key_exists('host', $parsedUrl)) {
            throw new Exception("cant parse url {$url}");
        }
        $path = "{$parsedUrl['scheme']}://{$parsedUrl['host']}";
        $path = isset($parsedUrl['path']) && $parsedUrl['path'] && $usePath
            ? "{$path}{$parsedUrl['path']}"
            : $path;
        $path = preg_replace('~/$~', '', $path);
        return strtolower($path ?? '');
    }

    private function saveFile(string $data, string $url): string
    {
        $fileName = $this->prepareFileName($url);
        $filePath = "{$this->outputDir}/{$fileName}";
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
        if ($name === null) {
            throw new Exception('Cant generate filename');
        }
        $ext = $ext ? ".{$ext}" : '';
        return "{$name}{$ext}";
    }
}
