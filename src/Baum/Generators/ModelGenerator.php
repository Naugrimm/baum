<?php

declare(strict_types=1);

namespace Baum\Generators;

use Illuminate\Contracts\Filesystem\FileNotFoundException;

class ModelGenerator extends Generator
{
    /**
     * Create a new model at the given path.
     *
     * @throws FileNotFoundException
     */
    public function create(string $name, string $path): string
    {
        $path = $this->getPath($name, $path);
        $stub = $this->getStub('model');
        $this->files->put($path, $this->parseStub($stub, [
            'table' => $this->tableize($name),
            'class' => $this->classify($name),
        ]));

        return $path;
    }

    /**
     * Get the full path name to the migration.
     */
    protected function getPath(string $name, string $path): string
    {
        return $path . '/' . $this->classify($name) . '.php';
    }
}
