<?php namespace Tisstech\BootstrapLaravelScaffold;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputArgument;

class ScaffoldCommand extends Command
{
    protected $name = 'scaffold';

    protected $description = "Makes layout, js/css, table, controller, model, views, seeds, and repository";

    public function __construct()
    {
        parent::__construct();
    }

    public function fire()
    {
        $scaffold = new Scaffold($this);

        $scaffold->setupLayoutFiles();

        $scaffold->createLayout();

        $scaffold->createModels();

        $this->info('Please wait a few moments...');

        $this->call('clear-compiled');

        $this->call('optimize');

        $this->info('Done!');
    }

    protected function getArguments()
    {
        return array(
            array('name', InputArgument::OPTIONAL, 'Name of the model/controller.'),
        );
    }
}
