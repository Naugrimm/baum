<?php

declare(strict_types=1);

namespace Baum\Console;

use Baum\Generators\MigrationGenerator;
use Baum\Generators\ModelGenerator;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Symfony\Component\Console\Input\InputArgument;

class InstallCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'baum:install';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scaffolds a new migration and model suitable for Baum.';

    /**
     * Create a new command instance
     */
    public function __construct(
        protected MigrationGenerator $migrator,
        protected ModelGenerator $modeler
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * Basically, we'll write the migration and model stubs out to disk inflected
     * with the name provided. Once its done, we'll `dump-autoload` for the entire
     * framework to make sure that the new classes are registered by the class
     * loaders.
     * @throws FileNotFoundException
     */
    public function handle(): void
    {
        /** @var string $name */
        $name = $this->input->getArgument('name');
        $this->writeMigration($name);
        $this->writeModel($name);
    }

    /**
     * Get the command arguments
     *
     * @return array<int, array<int, int|string>>
     */
    protected function getArguments(): array
    {
        return [['name', InputArgument::REQUIRED, 'Name to use for the scaffolding of the migration and model.']];
    }

    /**
     * Write the migration file to disk.
     * @throws FileNotFoundException
     */
    protected function writeMigration(string $name): void
    {
        $output = pathinfo($this->migrator->create($name, $this->getMigrationsPath()), PATHINFO_FILENAME);
        $this->line("      <fg=green;options=bold>create</fg=green;options=bold>  {$output}");
    }

    /**
     * Write the model file to disk.
     * @throws FileNotFoundException
     */
    protected function writeModel(string $name): void
    {
        $output = pathinfo($this->modeler->create($name, $this->getModelsPath()), PATHINFO_FILENAME);
        $this->line("      <fg=green;options=bold>create</fg=green;options=bold>  {$output}");
    }

    /**
     * Get the path to the migrations directory.
     */
    protected function getMigrationsPath(): string
    {
        return $this->laravel->databasePath();
    }

    /**
     * Get the path to the models directory.
     */
    protected function getModelsPath(): string
    {
        return $this->laravel->basePath();
    }
}
