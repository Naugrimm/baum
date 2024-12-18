<?php

declare(strict_types=1);

namespace Baum\Providers;

use Baum\Console\BaumCommand;
use Baum\Console\InstallCommand;
use Baum\Generators\MigrationGenerator;
use Baum\Generators\ModelGenerator;
use Illuminate\Support\ServiceProvider;

class BaumServiceProvider extends ServiceProvider
{
    /**
     * Baum version.
     *
     * @var string
     */
    public const VERSION = '1.1.1';

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->registerCommands();
    }

    /**
     * Register the commands.
     */
    public function registerCommands(): void
    {
        $this->registerBaumCommand();
        $this->registerInstallCommand();

        // Resolve the commands with Artisan by attaching the event listener to Artisan's
        // startup. This allows us to use the commands from our terminal.
        $this->commands('command.baum', 'command.baum.install');
    }

    /**
     * Register the 'baum' command.
     */
    protected function registerBaumCommand(): void
    {
        $this->app->singleton('command.baum', function ($app) {
            return new BaumCommand();
        });
    }

    /**
     * Register the 'baum:install' command.
     */
    protected function registerInstallCommand(): void
    {
        $this->app->singleton('command.baum.install', function ($app) {
            $migrator = new MigrationGenerator($app['files']);
            $modeler = new ModelGenerator($app['files']);

            return new InstallCommand($migrator, $modeler);
        });
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array<int,string>
     */
    public function provides(): array
    {
        return ['command.baum', 'command.baum.install'];
    }
}
