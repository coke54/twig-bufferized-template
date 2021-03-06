<?php
/*
 * This file is part of the Twig Bufferized Template package, an RunOpenCode project.
 *
 * (c) 2015 RunOpenCode
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace RunOpenCode\Twig\BufferizedTemplate;

use RunOpenCode\Twig\BufferizedTemplate\Tag\Bufferize\Node as BufferizeNode;
use RunOpenCode\Twig\BufferizedTemplate\Tag\TemplateBuffer\BaseBufferNode;
use RunOpenCode\Twig\BufferizedTemplate\Tag\TemplateBuffer\BufferBreakPoint;
use RunOpenCode\Twig\BufferizedTemplate\Tag\TemplateBuffer\Initialize;
use RunOpenCode\Twig\BufferizedTemplate\Tag\TemplateBuffer\Terminate;

/**
 * Class NodeVisitor
 *
 * Parses AST adding buffering tags on required templates.
 *
 * @package RunOpenCode\Twig\BufferizedTemplate
 */
class NodeVisitor extends \Twig_BaseNodeVisitor
{
    /**
     * @var array
     */
    private $settings;

    /**
     * @var string Current template name.
     */
    protected $filename;

    /**
     * @var bool Denotes if current template body should be bufferized.
     */
    private $shouldBufferize = false;

    /**
     * @var string Denotes current scope of AST (block or body).
     */
    private $currentScope;

    /**
     * @var \Twig_Node[] List of blocks for current template.
     */
    private $blocks;

    public function __construct(array $settings)
    {
        $this->settings = $settings;
    }

    /**
     * {@inheritdoc}
     */
    protected function doEnterNode(\Twig_Node $node, \Twig_Environment $env)
    {
        if ($node instanceof \Twig_Node_Module) {
            $this->filename = $node->getAttribute('filename');
        }

        if ($this->shouldProcess()) {

            if ($this->isBufferizingNode($node)) {
                $this->shouldBufferize = true;
            }

            if ($node instanceof \Twig_Node_Module) {
                $this->blocks = $node->getNode('blocks')->getIterator()->getArrayCopy();
            }

            if ($node instanceof \Twig_Node_Body) {
                $this->currentScope = null;
            }

            if ($node instanceof \Twig_Node_Block) {
                $this->currentScope = $node->getAttribute('name');
            }
        }

        return $node;
    }

    /**
     * {@inheritdoc}
     */
    protected function doLeaveNode(\Twig_Node $node, \Twig_Environment $env)
    {
        if ($node instanceof \Twig_Node_Module) {
            $this->filename = null;
        }

        if ($this->shouldProcess()) {

            if ($node instanceof \Twig_Node_Module) {

                if ($this->shouldBufferize) {

                    $node->setNode('body', new \Twig_Node(array(
                        new Initialize($this->settings['defaultExecutionPriority']),
                        $node->getNode('body'),
                        new Terminate($this->settings['defaultExecutionPriority'])
                    )));
                }

                $this->shouldBufferize = false;
                $this->blocks = array();
            }

            if ($this->isBufferizingNode($node)) {

                return new \Twig_Node(array(
                    new BufferBreakPoint($this->settings['defaultExecutionPriority']),
                    $node,
                    new BufferBreakPoint($this->settings['defaultExecutionPriority'], array(), array(BaseBufferNode::BUFFERIZED_EXECUTION_PRIORITY_ATTRIBUTE_NAME => $this->getNodeExecutionPriority($node)))
                ));
            } elseif ($node instanceof \Twig_Node_BlockReference && $this->hasBufferizingNode($this->blocks[$node->getAttribute('name')])) {

                return new \Twig_Node(array(
                    new BufferBreakPoint($this->settings['defaultExecutionPriority']),
                    $node,
                    new BufferBreakPoint($this->settings['defaultExecutionPriority'], array(), array(BaseBufferNode::BUFFERIZED_EXECUTION_PRIORITY_ATTRIBUTE_NAME => $this->getNodeExecutionPriority($node)))
                ));

            } elseif ($this->currentScope && $node instanceof \Twig_Node_Block && $this->hasBufferizingNode($node)) {

                $node->setNode('body', new \Twig_Node(array(
                    new Initialize($this->settings['defaultExecutionPriority']),
                    $node->getNode('body'),
                    new Terminate($this->settings['defaultExecutionPriority'])
                )));

                return $node;
            }

        }

        return $node;
    }

    /**
     * {@inheritdoc}
     */
    public function getPriority()
    {
        return $this->settings['nodeVisitorPriority'];
    }


    /**
     * Check if current template should be processed with node visitor based on whitelist or blacklist.
     *
     * @return bool
     */
    protected function shouldProcess()
    {
        if (count($this->settings['whitelist']) == 0 && count($this->settings['blacklist']) == 0) {
            return true;
        } elseif (count($this->settings['whitelist']) > 0) {
            return in_array($this->filename, $this->settings['whitelist']);
        } else {
            return !in_array($this->filename, $this->settings['blacklist']);
        }
    }

    /**
     * Check if provided node is node for bufferizing.
     *
     * @param \Twig_Node $node
     * @return bool
     */
    protected function isBufferizingNode(\Twig_Node $node = null)
    {
        if (is_null($node)) {

            return false;

        } else {

            foreach ($this->settings['nodes'] as $nodeClass => $priority) {

                if (is_a($node, $nodeClass)) {
                    return true;
                }
            }

        }

        return false;
    }

    /**
     * Checks if current node is asset injection node, or if such node exists in its sub-tree.
     *
     * @param \Twig_Node $node Node to check.
     * @return bool TRUE if this subtree has bufferizing node.
     */
    private function hasBufferizingNode(\Twig_Node $node = null)
    {
        if (is_null($node)) {
            return false;
        }

        if ($this->isBufferizingNode($node)) {
            return true;
        }

        $has = false;

        foreach ($node as $k => $n) {

            if ($this->isBufferizingNode($n)) {
                return true;
            } elseif ($n instanceof \Twig_Node_BlockReference && $this->hasBufferizingNode($this->blocks[$n->getAttribute('name')])) {
                return true;
            } else {
                $has = $has || $this->hasBufferizingNode($n);

                if ($has) {
                    return true;
                }
            }
        }

        return $has;
    }

    /**
     * Get execution priority of bufferized node.
     *
     * Get execution priority of bufferized node based on the node settings or configuration of the extension.
     *
     * @param \Twig_Node $node
     * @return mixed
     */
    private function getNodeExecutionPriority(\Twig_Node $node)
    {
        if ($node instanceof BufferizeNode && !is_null($node->getPriority())) {
            return $node->getPriority();
        }

        foreach ($this->settings['nodes'] as $nodeClass => $priority) {

            if (is_a($node, $nodeClass) && !is_null($priority)) {
                return $priority;
            }
        }

        return $this->settings['defaultExecutionPriority'];
    }
}