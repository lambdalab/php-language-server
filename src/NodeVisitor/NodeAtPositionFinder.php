<?php
declare(strict_types = 1);

namespace LanguageServer\NodeVisitor;

use PhpParser\{NodeVisitorAbstract, Node, NodeTraverser};
use LanguageServer\Protocol\{Position, Range};

/**
 * Finds the Node at a specified position
 * Depends on ColumnCalculator
 */
class NodeAtPositionFinder extends NodeVisitorAbstract
{
    /**
     * The node at the position, if found
     *
     * @var Node|null
     */
    public $node;

    /**
     * @var Position
     */
    private $position;

    /**
     * @var Position
     */
    private $startPos;

    /**
     * @var Position
     */
    private $endPos;

    /**
     * @param Position $position The position where the node is located
     */
    public function __construct(Position $position)
    {
        $this->position = $position;

        $this->startPos = new Position(0, 0);
        $this->endPos  = new Position(0, 0);
    }

    public function leaveNode(Node $node)
    {
        if ($this->node === null) {
            //$range = Range::fromNode($node);

            // avoid creating objects.
            $startLine = $node->getAttribute('startLine') - 1;
            $endLine = $node->getAttribute('endLine') - 1;

            $this->startPos->character = $node->getAttribute('startColumn') - 1;
            $this->endPos->character = $node->getAttribute('endColumn');

            //print_r($range);
            //if ($this->startPos->compare($this->position) <= 0 && $this->endPos->compare($this->position) >= 0/*$range->includes($this->position)*/) {
            if ($startLine <= $this->position->line && $endLine >= $this->position->line) {
              $startChar  = $node->getAttribute('startColumn') - 1;
              $endChar  = $node->getAttribute('endColumn');
              
                $this->node = $node;
                return NodeTraverser::STOP_TRAVERSAL;
            }
        }
    }
}
