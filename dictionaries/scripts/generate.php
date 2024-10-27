<?php

use Dedoc\Scramble\Infer\Scope\Index;
use Dedoc\Scramble\Infer\Services\FileParser;
use Dedoc\Scramble\Infer\Services\ShallowAnalyzer;
use PhpParser\ParserFactory;

include 'vendor/autoload.php';

$files = [
    __DIR__.'/../core.php',
];

app()->singleton(FileParser::class, function () {
    return new FileParser(
        (new ParserFactory)->createForHostVersion()
    );
});
app()->singleton(Index::class);

foreach ($files as $file) {
    $content = file_get_contents($file);

    (new ShallowAnalyzer(['temp' => $content]))
        ->buildIndex(app(Index::class));
}

$classesDefinitions = [];
foreach (app(Index::class)->classesDefinitions as $definition) {
    $classesDefinitions[$definition->name] = serialize($definition);
}

$def = var_export($classesDefinitions, true);
file_put_contents(__DIR__ . '/../classMap.php', <<<EOL
<?php
/*
 * Do not change! This file is generated via scripts/generate.php.
 */
return {$def};
EOL);
