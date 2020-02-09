<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class RefreshList extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'list:refresh';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Refresh lists in all groups.';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        /*
        Algorithm (for each group):
        1. Get bot's post with list and reposts.
        2. For each repost get number of views on user's wall.
        3. Sort users according to number of views.
        4. Generate twig template.
        5. Edit post with new template.
        */
    }
}
