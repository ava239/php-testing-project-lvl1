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
        $fileName = $this->saveFile($data, $url, '.html');

        return $fileName;
    }

    public function getUrl(string $url): string
    {
        return $this->httpClient->get($url)->getBody()->getContents();
    }

    private function saveFile(string $data, string $url, string $postfix = ''): string
    {
        $fileName = $this->prepareFileName($url) . $postfix;
        $filePath = "{$this->outputDir}/{$fileName}";
        file_put_contents($filePath, $data);
        return $filePath;
    }

    private function prepareFileName(string $url): string
    {
        $schema = parse_url($url, PHP_URL_SCHEME);
        $name = preg_replace(["~$schema://~", '~[^\d\w]~'], ['', '-'], $url);
        if ($name === null) {
            throw new Exception('Cant generate filename');
        }
        return $name;
    }
}
