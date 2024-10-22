<?php

namespace Dedoc\Scramble\Infer\Services;

use Dedoc\Scramble\Infer\Scope\Index;
use Dedoc\Scramble\Infer\Scope\NodeTypesResolver;
use Dedoc\Scramble\Infer\Scope\Scope;
use Dedoc\Scramble\Infer\Scope\ScopeContext;
use Dedoc\Scramble\Infer\Visitors\ShallowClassAnalyzingVisitor;
use PhpParser\ErrorHandler\Throwing;
use PhpParser\NameContext;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

class ShallowAnalyzer
{
    private array $symbolsTable = [];

    /**
     * @param  array<string, string>  $locations  Key is a filename, and value is a code
     */
    public function __construct(
        private array $locations,
    ) {}

    public function buildIndex(Index $index = new Index): Index
    {
        $this->buildSymbolsTable();

        // 2. order the symbols so non-dependent ones (or that ones that does not have dependency in the list) go first
        $keyedSymbols = collect($this->symbolsTable)->keyBy('name');

        $sortedSymbols = collect($this->symbolsTable)
            ->sort(function (Symbol $a, Symbol $b) use ($keyedSymbols) {
                if (! $a->extends) {
                    return -1;
                }
                if (! $keyedSymbols->has($a->extends)) {
                    return -1;
                }

                return $b->name === $a->extends
                    ? -1
                    : 0;
            });

        // 3. analyze in order
        $sortedSymbols->each(fn (Symbol $s) => $this->analyzeSymbol($s, $index));

        return $index;
    }

    private function analyzeSymbol(Symbol $symbol, Index $index)
    {
        $code = $this->locations[$symbol->location];

        $nodes = app(FileParser::class)->parseContent($code)->getStatements();

        $traverser = new NodeTraverser;
        $traverser->addVisitor(new ShallowClassAnalyzingVisitor(
            index: $index,
            scope: new Scope($index, new NodeTypesResolver, new ScopeContext, new FileNameResolver(tap(new NameContext(new Throwing), fn (NameContext $nc) => $nc->startNamespace()))),
            symbol: $symbol,
        ));
        $traverser->traverse($nodes);
    }

    private function buildSymbolsTable()
    {
        foreach ($this->locations as $source => $code) {
            $this->symbolsTable = array_merge($this->symbolsTable, $this->getCodeSymbols($source, $code));
        }
    }

    private function getCodeSymbols(string $source, string $code)
    {
        $nodes = app(FileParser::class)->parseContent($code)->getStatements();
        $traverser = new NodeTraverser;
        $traverser->addVisitor($symbolsExtractor = new class($source) extends NodeVisitorAbstract
        {
            public function __construct(private string $source, public array $symbols = []) {}

            public function enterNode(Node $node)
            {
                if ($node instanceof Node\Stmt\Class_) {
                    $this->symbols[] = new Symbol(
                        type: 'class',
                        name: $node->name->toString(),
                        location: $this->source,
                        extends: $node->extends?->toString(),
                    );

                    return NodeVisitor::DONT_TRAVERSE_CHILDREN;
                }

                if ($node instanceof Node\Stmt\Function_) {
                    $this->symbols[] = new Symbol(
                        type: 'function',
                        name: $node->name->toString(),
                        location: $this->source,
                    );

                    return NodeVisitor::DONT_TRAVERSE_CHILDREN;
                }

                return null;
            }
        });
        $traverser->traverse($nodes);

        return $symbolsExtractor->symbols;
    }
}
