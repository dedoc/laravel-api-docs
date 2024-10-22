<?php

use Dedoc\Scramble\Infer\Scope\Index;
use Dedoc\Scramble\Infer\Scope\NodeTypesResolver;
use Dedoc\Scramble\Infer\Scope\Scope;
use Dedoc\Scramble\Infer\Scope\ScopeContext;
use Dedoc\Scramble\Infer\Services\FileNameResolver;
use Dedoc\Scramble\Infer\Services\FileParser;
use Dedoc\Scramble\Infer\Visitors\ShallowClassAnalyzingVisitor;
use PhpParser\ErrorHandler\Throwing;
use PhpParser\NameContext;
use PhpParser\NodeTraverser;
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

    analyzeFile($content);
}

function analyzeFile(string $code)
{
    $index = app(Index::class);

    $nodes = app(FileParser::class)->parseContent($code)->getStatements();

    $traverser = new NodeTraverser;
    $traverser->addVisitor(new ShallowClassAnalyzingVisitor(
        index: $index = new Index,
        scope: new Scope($index, new NodeTypesResolver, new ScopeContext, new FileNameResolver(tap(new NameContext(new Throwing), fn (NameContext $nc) => $nc->startNamespace())))
    ));
    $traverser->traverse($nodes);

    dd($index);

}

$classesDefinitions = [];
foreach ($classes as $className) {
    $classesDefinitions[$className] = generateClassDefinitionInitialization($className);
}

function generateClassDefinitionInitialization(string $name)
{
    $classAnalyzer = app(\Dedoc\Scramble\Infer\Analyzer\ClassAnalyzer::class);

    $classDefinition = $classAnalyzer->analyze($name);
    foreach ($classDefinition->methods as $methodName => $method) {
        $classDefinition->getMethodDefinition($methodName);
    }

    return serialize($classAnalyzer->analyze($name));
}

$def = var_export($classesDefinitions, true);
file_put_contents(__DIR__.'/../classMap_v2.php', <<<EOL
<?php
/*
 * Do not change! This file is generated via scripts/generate.php.
 */
return {$def};
EOL);
