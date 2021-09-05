<?php

namespace Ava239\Page\Loader;

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

        $data = $this->getUrl($url);
        $fileName = $this->saveFile($data, $url);

        return $fileName;
    }

    public function getUrl(string $url): string
    {
        return $this->httpClient->get($url)->getBody()->getContents();
    }

    private function saveFile(string $data, string $url): string
    {
        $fileName = $this->prepareFileName($url);
        $filePath = "{$this->outputDir}/{$fileName}";
        file_put_contents($filePath, $data);
        return $filePath;
    }

    public function prepareFileName(string $url, string $defaultExt = 'html'): string
    {
        $schema = parse_url($url, PHP_URL_SCHEME);
        $path = parse_url($url, PHP_URL_PATH);
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $ext = $ext ?: $defaultExt;
        $replaceExt = $ext ? "~\.{$ext}~" : '~~';
        $name = preg_replace(["~$schema://~", $replaceExt, '~[^\d\w]~'], ['', '', '-'], $url);
        if ($name === null) {
            throw new Exception('Cant generate filename');
        }
        $ext = $ext ? ".{$ext}" : '';
        return "{$name}{$ext}";
    }
}
