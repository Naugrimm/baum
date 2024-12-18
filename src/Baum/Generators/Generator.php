<?php

declare(strict_types=1);

namespace Baum\Generators;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

abstract class Generator
{
    /**
     * Create a new MigrationGenerator instance.
     */
    public function __construct(
        protected Filesystem $files
    ) {
    }

    /**
     * Get the path to the stubs.
     */
    public function getStubPath(): string
    {
        return __DIR__ . '/stubs';
    }

    /**
     * Get the filesystem instance.
     */
    public function getFilesystem(): Filesystem
    {
        return $this->files;
    }

    /**
     * Get the given stub by name.
     * @throws FileNotFoundException
     */
    protected function getStub(string $name): string
    {
        if (stripos($name, '.stub') === false) {
            $name = $name . '.stub';
        }

        return $this->files->get($this->getStubPath() . '/' . $name);
    }

    /**
     * Parse the provided stub and replace via the array given.
     *
     * @param array<string,string> $replacements
     */
    protected function parseStub(string $stub, array $replacements = []): string
    {
        $output = $stub;

        foreach ($replacements as $key => $replacement) {
            $search = '{{' . $key . '}}';
            $output = str_replace($search, $replacement, $output);
        }

        return $output;
    }

    /**
     * Inflect to a class name.
     */
    protected function classify(string $input): string
    {
        return Str::of($input)->singular()->studly()->toString();
    }

    /**
     * Inflect to table name.
     */
    protected function tableize(string $input): string
    {
        return Str::of($input)->plural()->snake()->toString();
    }
}
