<?php
declare(strict_types = 1);

namespace LanguageServer\NodeVisitor;

use PhpParser\{NodeVisitorAbstract, Node, NodeTraverser};
use LanguageServer\Protocol\{Position, Range};

/**
 * Finds the Node at a specified position
 * Depends on ColumnCalculator
 */
class NodeLocFinder extends NodeVisitorAbstract
{
    /**
     * @var Node[]
     */
    public $positions;

    public function __construct()
    {
    }

    public function leaveNode(Node $node)
    {
      $fromPos = $node->getAttribute('startFilePos');
      $toPos = $node->getAttribute('endFilePos');

      for ($i = $fromPos; $i < $toPos; $i++) {
        if (!isset($this->positions[$i])) {
          $this->positions[$i] = $node;
        }
      }
    }
}
