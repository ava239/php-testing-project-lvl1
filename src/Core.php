<?php

namespace Ava239\Page\Loader;

use Exception;

class Core
{
    private string $outputDir;

    public function __constructor(string $output = './'): void
    {
        $realDirpath = realpath($output);
        if ($realDirpath === false || !is_dir($realDirpath)) {
            throw new Exception("'$output' is not a directory");
        }
        if (!is_readable($realDirpath)) {
            throw new Exception("Can`t read '$output'");
        }
        $this->outputDir = $realDirpath;
    }

    public function download(string $url): string
    {
        return "Page was successfully loaded into {$this->outputDir}";
    }
}
