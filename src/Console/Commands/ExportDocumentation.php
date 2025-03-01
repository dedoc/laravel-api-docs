<?php

namespace Dedoc\Scramble\Console\Commands;

use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ExportDocumentation extends Command
{
    protected $signature = 'scramble:export
        {--path= : The path to save the exported JSON file}
        {--api=default : The API to export a documentation for}
        {--all : Ignore exclude attributes, export all routes}
    ';

    protected $description = 'Export the OpenAPI document to a JSON file.';

    public function handle(Generator $generator): void
    {
        $config = Scramble::getGeneratorConfig($api = $this->option('api'));

        if ($this->option('all')) {
            $config->set('ignore_exclude_attributes', true);
        }

        $specification = json_encode($generator($config), JSON_PRETTY_PRINT);

        /** @var string $filename */
        $filename = $this->option('path') ?? $config->get('export_path', 'api'.($api === 'default' ? '' : "-$api").'.json');

        File::put($filename, $specification);

        $this->info("OpenAPI document exported to {$filename}.");
    }
}
