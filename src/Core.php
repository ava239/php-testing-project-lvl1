<?php

namespace Ava239\Page\Loader;

use GuzzleHttp\Client;

class Core
{
    private string $outputDir;
    private Client $httpClient;

    public function __construct(string $outputDir, Client $httpClient = null)
    {
        $realDirpath = realpath($outputDir);
        $this->outputDir = $realDirpath !== false
            ? $realDirpath
            : $outputDir;
        $this->httpClient = $httpClient ?? new Client();
    }

    public function download(string $url): string
    {
        $data = $this->load($url);

        file_put_contents("{$this->outputDir}/{$this->generateFileName($url)}", $data);

        return "Page was successfully loaded into {$this->outputDir}";
    }

    public function load(string $url): string
    {
        return $this->httpClient->get($url)->getBody()->getContents();
    }

    public function generateFileName(string $url): string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        return preg_replace(["~$scheme://~", '~[^\d\w]~'], ['', '-'], $url) . '.html';
    }
}
