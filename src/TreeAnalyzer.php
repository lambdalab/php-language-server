<?php
declare(strict_types = 1);

namespace LanguageServer;

use LanguageServer\Protocol\{Diagnostic, DiagnosticSeverity, Range, Position, TextEdit};
use LanguageServer\Index\Index;
use phpDocumentor\Reflection\{DocBlockFactory, Fqsen, Types, Type};
use Sabre\Uri;
use Microsoft\PhpParser;
use Microsoft\PhpParser\Node;

// For autoloading.
use LanguageServer\Protocol\{SymbolInformation, SymbolKind, Location};

class TreeAnalyzer
{
    /** @var PhpParser\Parser */
    private $parser;

    /** @var Node\SourceFileNode */
    private $sourceFileNode;

    /** @var Diagnostic[] */
    private $diagnostics;

    /** @var string */
    private $content;

    /** @var Node[] */
    private $referenceNodes;

    /** @var Definition[] */
    private $definitions;

    /** @var Node[] */
    private $definitionNodes;

    /**
     * @param PhpParser\Parser $parser
     * @param string $content
     * @param DocBlockFactory $docBlockFactory
     * @param DefinitionResolver $definitionResolver
     * @param string $uri
     */
    public function __construct(PhpParser\Parser $parser, string $content, DocBlockFactory $docBlockFactory, DefinitionResolver $definitionResolver, string $uri)
    {
        $this->parser = $parser;
        $this->docBlockFactory = $docBlockFactory;
        $this->definitionResolver = $definitionResolver;
        $this->sourceFileNode = $this->parser->parseSourceFile($content, $uri);

        // TODO - docblock errors

        $this->collectDefinitionsAndReferences($this->sourceFileNode);
    }

    private function collectDefinitionsAndReferences(Node $sourceFileNode)
    {
        foreach ($sourceFileNode::CHILD_NAMES as $name) {
            $node = $sourceFileNode->$name;

            if ($node === null) {
                continue;
            }

            if (\is_array($node)) {
                foreach ($node as $child) {
                    if ($child instanceof Node) {
                        $this->update($child);
                    }
                }
                continue;
            }

            if ($node instanceof Node) {
                $this->update($node);
            }

            if (($error = PhpParser\DiagnosticsProvider::checkDiagnostics($node)) !== null) {
                $range = PhpParser\PositionUtilities::getRangeFromPosition($error->start, $error->length, $this->sourceFileNode->fileContents);

                $this->diagnostics[] = new Diagnostic(
                    $error->message,
                    new Range(
                        new Position($range->start->line, $range->start->character),
                        new Position($range->end->line, $range->start->character)
                    ),
                    null,
                    DiagnosticSeverity::ERROR,
                    'php'
                );
            }
        }
    }

    /**
     * Collect definitions and references for the given node
     *
     * @param Node $node
     */
    private function update(Node $node)
    {

        $fqn = ($this->definitionResolver)::getDefinedFqn($node);
        // Only index definitions with an FQN (no variables)
        if ($fqn !== null) {
            $this->definitionNodes[$fqn] = $node;
            $this->definitions[$fqn] = $this->definitionResolver->createDefinitionFromNode($node, $fqn);
        } else {
            $parent = $node->parent;
            if (!(
                (
                    // $node->parent instanceof Node\Expression\ScopedPropertyAccessExpression ||
                    ($node instanceof Node\Expression\ScopedPropertyAccessExpression ||
                    $node instanceof Node\Expression\MemberAccessExpression)
                    && !(
                        $node->parent instanceof Node\Expression\CallExpression ||
                        $node->memberName instanceof PhpParser\Token
                    ))
                || ($parent instanceof Node\Statement\NamespaceDefinition && $parent->name !== null && $parent->name->getStart() === $node->getStart()))
            ) {
                $fqn = $this->definitionResolver->resolveReferenceNodeToFqn($node);
                if ($fqn !== null) {
                    $this->addReference($fqn, $node);

                    if (
                        $node instanceof Node\QualifiedName
                        && ($node->isQualifiedName() || $node->parent instanceof Node\NamespaceUseClause)
                        && !($parent instanceof Node\Statement\NamespaceDefinition && $parent->name->getStart() === $node->getStart()
                        )
                    ) {
                        // Add references for each referenced namespace
                        $ns = $fqn;
                        while (($pos = strrpos($ns, '\\')) !== false) {
                            $ns = substr($ns, 0, $pos);
                            $this->addReference($ns, $node);
                        }
                    }

                    // Namespaced constant access and function calls also need to register a reference
                    // to the global version because PHP falls back to global at runtime
                    // http://php.net/manual/en/language.namespaces.fallback.php
                    if (ParserHelpers\isConstantFetch($node) ||
                        ($parent instanceof Node\Expression\CallExpression
                            && !(
                                $node instanceof Node\Expression\ScopedPropertyAccessExpression ||
                                $node instanceof Node\Expression\MemberAccessExpression
                            ))) {
                        $parts = explode('\\', $fqn);
                        if (count($parts) > 1) {
                            $globalFqn = end($parts);
                            $this->addReference($globalFqn, $node);
                        }
                    }
                }
            }
        }

        // ***** start of lambdalab-specific code
        // Collect autoload
        $this->updateAutoload($node);

        // Dynamic loading handler
        if ($node instanceof Node\Expression\MemberAccessExpression) {
          $this->handleDynamicLoadNode($node);
        }

        /*
        // Autoloading handler
        if ($node instanceof Node\Statement\ClassDeclaration) {
          $this->handleAutoload($node);
        }
        //***** end of lambdalab-specific code
        */

        $this->collectDefinitionsAndReferences($node);
    }

    // This function records autoload information.
    public function updateAutoload(Node $node) {
      if (!$node instanceof Node\Expression\AssignmentExpression) {
        return;
      }

      $lhs = $node->leftOperand;
      if (!$lhs instanceof Node\Expression\SubscriptExpression) {
        return;
      }

      $pfe = $lhs->postfixExpression;
      $pfeName = $pfe->getText();
      if ($pfeName != "\$autoload") {
        return;
      }

      $rhs = $node->rightOperand;
      if (!$rhs instanceof Node\Expression\ArrayCreationExpression) {
        return;
      }

      $type = $lhs->accessExpression; // we have retrieved the autoload type.
      
      if (!isset($rhs->arrayElements)) {
        return;
      }
      $moduleList = $rhs->arrayElements;

      // now we have a list of quoted modules.
      foreach ($moduleList->getValues() as $lib) {
        $libName = substr($lib->getText(), 1, -1);
        switch ($type->getText()) {
        case "'libraries'":
          $this->definitionResolver->autoloadLibraries[$libName] = $lib;
          break;
        case "'helper'":
          $this->definitionResolver->autoloadHelpers[$libName] = $lib;
          break;
        case "'config'":
          $this->definitionResolver->autoloadConfig[$libName] = $lib;
          break;
        case "'model'":
          $this->definitionResolver->autoloadModels[$libName] = $lib;
          break;
        case "'language'":
          $this->definitionResolver->autoloadLanguage[$libName] = $lib;
          break;
        }
      }
    }


    // This function fills autoload fields.
    public function handleAutoload(Node $node) {
      if (!$node instanceof Node\Statement\ClassDeclaration) {
        return;
      }

      if (!isset($node->classBaseClause)) {
        return;
      }
      $baseClause = $node->classBaseClause;

      if (!isset($baseClause->baseClass)) {
        return;
      }

      $baseClasses = $baseClause->baseClass;

      $shouldAutoload = false;

      if (is_array($baseClasses)) {
        foreach ($baseClasses as $base) {
          // each $base is a Qualified name:
          if (isset($base)) {
            $baseClass = $base->getText();
            if ($baseClass == "CI_Controller" ||
              $baseClass == "ST_Controller" ||
              $baseClass == "ST_Auth_Controller") {
              $shouldAutoload = true;
            } 
          }
        }
      } else {
        $baseClass = $baseClasses->getText();
        if ($baseClass == "CI_Controller" ||
          $baseClass == "ST_Controller" ||
          $baseClass == "ST_Auth_Controller") {
          $shouldAutoload = true;
        } 
      } 
      
      if (!$shouldAutoload) {
        return;
      }

      if (isset($this->definitionResolver->autoloadLibraries)) {
        foreach ($this->definitionResolver->autoloadLibraries as $key => $value) {
          $this->createAutoloadDefinition($node, $key, $value);
        }
      }

      if (isset($this->definitionResolver->autoloadModels)) {
        foreach ($this->definitionResolver->autoloadModels as $key => $value) {
          $this->createAutoloadDefinition($node, $key, $value);
        }
      }

      if (isset($this->definitionResolver->autoloadHelpers)) {
        foreach ($this->definitionResolver->autoloadHelpers as $key => $value) {
          $this->createAutoloadDefinition($node, $key, $value);
        }
      }

      if (isset($this->definitionResolver->autoloadConfig)) {
        foreach ($this->definitionResolver->autoloadConfig as $key => $value) {
          $this->createAutoloadDefinition($node, $key, $value);
        }
      }

      if (isset($this->definitionResolver->autoloadLanguage)) {
        foreach ($this->definitionResolver->autoloadLanguage as $key => $value) {
          $this->createAutoloadDefinition($node, $key, $value);
        }
      }
    }

    public function isDynamicLoadNode(Node $node) {
      $text = $node->getText();

      // TODO: add more checks.

      $model = "load->model";
      if (substr($text, -strlen($model)) == $model) {
        return true;
      }

      $lib = "load->library";
      if (substr($text, -strlen($lib)) == $lib) {
        return true;
      }

      $helper = "load->helper";
      if (substr($text, -strlen($helper)) == $helper) {
        return true;
      }

      return false;
    }

    public function handleDynamicLoadNode(Node $node) {
      if (!$this->isDynamicLoadNode($node)) {
        return;
      }

      $argListG = $node->getParent()->argumentExpressionList->getValues();

      $argList = [];
      foreach ($argListG as $arg) {
        $argList[] = $arg;
      }

      $argSize = count($argList);

      if ($argSize == 0 || $argSize == 3) { // when argSize = 3 it's loading from db
        return;
      }

      $nameNode = NULL;

      if (!isset($argList[0]->expression)) {
        return;
      }
      $argExpression = $argList[0]->expression;

      if ($argExpression instanceof Node\StringLiteral) {
        // make sure the first argument is a string.

        if ($argSize == 2) {
          if (!isset($argList[1]->expression)) {
            return;
          }
          $nameNode = $argList[1]->expression;
        }
        $this->createDefinition($node, $argExpression, $nameNode);
      } 
      // TODO: enable arrays
      /*else if ($node->args[0]->value instanceof Node\Expr\Array_) {
        $elems = $node->args[0]->value->items;
        foreach ($elems as $item) {
          if ($item->value instanceof Node\Scalar\String_) {
            $this->createDefinition($node, $item->value, $nameNode);
          }
        }
      }*/
    }

    public function insertDefinition(String $fqn, String $fieldName, String $classFqn, String $entityName, Node $entityNode, Bool $shouldCopy) {
      $this->definitionNodes[$fqn] = $entityNode;
      
      // create symbol definition:
      $sym = new SymbolInformation($fieldName, SymbolKind::PROPERTY, Location::fromNode($entityNode), $classFqn);

      $typeName = $shouldCopy ? ucwords($fieldName) : ucwords($entityName);
      $fqsen = new Fqsen('\\' . $typeName); 
      $type = new Types\Object_($fqsen);

      // Create defintion from symbol, type and all others
      $def = new Definition;
      $def->canBeInstantiated = false;
      $def->isGlobal = false; // TODO check the meaning of this, why public field has this set to false?
      $def->isStatic = false; // it should not be a static field
      $def->fqn = $fqn;
      $def->symbolInformation = $sym;
      $def->type = $type;
      // Maybe this is not the best
      $def->declarationLine = $fieldName; // $this->prettyPrinter->prettyPrint([$argNode]);
      $def->documentation = "Dynamically Generated Field: " . $fieldName;

      $this->definitions[$fqn] = $def;
    }

    public function createAutoloadDefinition(Node $classNode, String $key, Node $entityNode) {
      $fieldName = $key;
      $enclosedClass = $classNode;
      $classFqn = $enclosedClass->getNamespacedName()->getFullyQualifiedNameText();
      $fqn = $classFqn . "->" . $fieldName;

      // if we cannot find definition, just return.
      if ($fqn === NULL) {
        return;
      }

      $this->insertDefinition($fqn, $fieldName, $classFqn, $fieldName, $entityNode, true);
    }

    public function createDefinition($callNode, $entityNode, $nameNode) {
      $entityString = substr($entityNode->getText(), 1, -1);
      $entityParts = explode('\\', $entityString);
      $enityName = array_pop($entityParts);
      $fieldName = $enityName;

      // deal with case like:   $this->_CI->load->model('users_mdl', 'hahaha');
      /*
      if ($callNode->name = "model" && $nameNode !== NULL) {
        if (!($nameNode instanceof Node\Scalar\String_)) {
          return;
        }
        $fieldName = $nameNode->value;
      }
      */

      $enclosedClass = $callNode;
      $fqn = NULL;
      $classFqn = NULL;
      while ($enclosedClass !== NULL) {
        $enclosedClass = $enclosedClass->getParent();
        if ($enclosedClass instanceof Node\Statement\ClassDeclaration) {
          $classFqn = $enclosedClass->getNamespacedName()->getFullyQualifiedNameText();
          // TODO: verify this
          $fqn = $classFqn . '->' . $fieldName;
          break;
        }
      }

      if ($fqn == NULL) {
        return;
      }

      $entityName = (string)$fieldName;
      $this->insertDefinition($fqn, $fieldName, $classFqn, $entityName, $entityNode, false);
    }

    /**
     * @return Diagnostic[]
     */
    public function getDiagnostics(): array
    {
        return $this->diagnostics ?? [];
    }

    /**
     * @return void
     */
    private function addReference(string $fqn, Node $node)
    {
        if (!isset($this->referenceNodes[$fqn])) {
            $this->referenceNodes[$fqn] = [];
        }
        $this->referenceNodes[$fqn][] = $node;
    }

    /**
     * @return Definition
     */
    public function getDefinitions()
    {
        return $this->definitions ?? [];
    }

    /**
     * @return Node[]
     */
    public function getDefinitionNodes()
    {
        return $this->definitionNodes ?? [];
    }

    /**
     * @return Node[]
     */
    public function getReferenceNodes()
    {
        return $this->referenceNodes ?? [];
    }

    /**
     * @return Node\SourceFileNode
     */
    public function getSourceFileNode()
    {
        return $this->sourceFileNode;
    }
}
