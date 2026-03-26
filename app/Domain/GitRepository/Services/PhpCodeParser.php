<?php

declare(strict_types=1);

namespace App\Domain\GitRepository\Services;

use App\Domain\GitRepository\DTOs\CodeElementData;
use PhpParser\Error as PhpParserError;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;

/**
 * Parses PHP source files using nikic/php-parser and extracts CodeElementData DTOs
 * for classes, methods, and functions. Skips files that fail to parse gracefully.
 */
class PhpCodeParser
{
    private Parser $parser;

    private PrettyPrinter $printer;

    public function __construct()
    {
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
        $this->printer = new PrettyPrinter;
    }

    /**
     * Parse the content of a PHP file and return CodeElementData DTOs.
     * Returns an empty array if the file cannot be parsed.
     *
     * @return CodeElementData[]
     */
    public function parseFile(string $filePath, string $content): array
    {
        try {
            $stmts = $this->parser->parse($content);
        } catch (PhpParserError) {
            return [];
        }

        if ($stmts === null) {
            return [];
        }

        $elements = [];

        $traverser = new NodeTraverser;
        $visitor = new class($filePath, $this, $elements) extends NodeVisitorAbstract
        {
            /** @param CodeElementData[] $elements */
            public function __construct(
                private readonly string $filePath,
                private readonly PhpCodeParser $parser,
                private array &$elements,
            ) {}

            public function enterNode(Node $node): null|int|Node
            {
                if ($node instanceof Class_ && $node->name !== null) {
                    $this->elements[] = $this->parser->buildClassElement($node, $this->filePath);
                } elseif ($node instanceof ClassMethod) {
                    $this->elements[] = $this->parser->buildMethodElement($node, $this->filePath);
                } elseif ($node instanceof Function_) {
                    $this->elements[] = $this->parser->buildFunctionElement($node, $this->filePath);
                }

                return null;
            }
        };

        $traverser->addVisitor($visitor);
        $traverser->traverse($stmts);

        return $elements;
    }

    /** @internal Used by the anonymous visitor class. */
    public function buildClassElement(Class_ $node, string $filePath): CodeElementData
    {
        $name = $node->name->toString();
        $signature = $this->buildClassSignature($node);
        $docstring = $this->extractDocComment($node);

        return new CodeElementData(
            elementType: 'class',
            name: $name,
            filePath: $filePath,
            lineStart: $node->getStartLine() > 0 ? $node->getStartLine() : null,
            lineEnd: $node->getEndLine() > 0 ? $node->getEndLine() : null,
            signature: $signature,
            docstring: $docstring,
            contentHash: hash('sha256', $signature.$docstring),
        );
    }

    /** @internal Used by the anonymous visitor class. */
    public function buildMethodElement(ClassMethod $node, string $filePath): CodeElementData
    {
        $name = $node->name->toString();
        $signature = $this->buildMethodSignature($node);
        $docstring = $this->extractDocComment($node);

        return new CodeElementData(
            elementType: 'method',
            name: $name,
            filePath: $filePath,
            lineStart: $node->getStartLine() > 0 ? $node->getStartLine() : null,
            lineEnd: $node->getEndLine() > 0 ? $node->getEndLine() : null,
            signature: $signature,
            docstring: $docstring,
            contentHash: hash('sha256', $signature.$docstring),
        );
    }

    /** @internal Used by the anonymous visitor class. */
    public function buildFunctionElement(Function_ $node, string $filePath): CodeElementData
    {
        $name = $node->name->toString();
        $signature = $this->buildFunctionSignature($node);
        $docstring = $this->extractDocComment($node);

        return new CodeElementData(
            elementType: 'function',
            name: $name,
            filePath: $filePath,
            lineStart: $node->getStartLine() > 0 ? $node->getStartLine() : null,
            lineEnd: $node->getEndLine() > 0 ? $node->getEndLine() : null,
            signature: $signature,
            docstring: $docstring,
            contentHash: hash('sha256', $signature.$docstring),
        );
    }

    private function extractDocComment(Node $node): ?string
    {
        $doc = $node->getDocComment();

        return $doc ? $doc->getText() : null;
    }

    private function buildClassSignature(Class_ $node): string
    {
        $parts = [];

        if ($node->isAbstract()) {
            $parts[] = 'abstract';
        }

        if ($node->isFinal()) {
            $parts[] = 'final';
        }

        if ($node->isReadonly()) {
            $parts[] = 'readonly';
        }

        $parts[] = 'class';
        $parts[] = $node->name->toString();

        if ($node->extends !== null) {
            $parts[] = 'extends';
            $parts[] = $node->extends->toString();
        }

        if ($node->implements !== []) {
            $parts[] = 'implements';
            $parts[] = implode(', ', array_map(fn ($i) => $i->toString(), $node->implements));
        }

        return implode(' ', $parts);
    }

    private function buildMethodSignature(ClassMethod $node): string
    {
        $flags = [];

        if ($node->isPublic()) {
            $flags[] = 'public';
        } elseif ($node->isProtected()) {
            $flags[] = 'protected';
        } elseif ($node->isPrivate()) {
            $flags[] = 'private';
        }

        if ($node->isStatic()) {
            $flags[] = 'static';
        }

        if ($node->isAbstract()) {
            $flags[] = 'abstract';
        }

        if ($node->isFinal()) {
            $flags[] = 'final';
        }

        $flags[] = 'function';
        $flags[] = $node->name->toString().'('.$this->buildParamList($node->params).')';

        if ($node->returnType !== null) {
            $flags[] = ': '.$this->printer->prettyPrint([$node->returnType]);
        }

        return implode(' ', $flags);
    }

    private function buildFunctionSignature(Function_ $node): string
    {
        $sig = 'function '.$node->name->toString().'('.$this->buildParamList($node->params).')';

        if ($node->returnType !== null) {
            $sig .= ': '.$this->printer->prettyPrint([$node->returnType]);
        }

        return $sig;
    }

    /** @param Node\Param[] $params */
    private function buildParamList(array $params): string
    {
        return implode(', ', array_map(function (Node\Param $p): string {
            $parts = [];

            if ($p->type !== null) {
                $parts[] = $this->printer->prettyPrint([$p->type]);
            }

            $name = '$'.($p->var instanceof Node\Expr\Variable ? (string) $p->var->name : '?');
            $parts[] = $name;

            return implode(' ', $parts);
        }, $params));
    }
}
