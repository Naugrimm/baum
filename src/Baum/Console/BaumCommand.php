<?php

declare(strict_types=1);

namespace Baum\Console;

use Baum\Providers\BaumServiceProvider as Baum;
use Illuminate\Console\Command;

class BaumCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'baum';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Get Baum version notice.';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->line('<info>Baum</info> version <comment>' . Baum::VERSION . '</comment>');
        $this->line('A Nested Set pattern implementation for the Eloquent ORM.');
        $this->line('<comment>Copyright (c) 2013 Estanislau Trepat</comment>');
    }
}
