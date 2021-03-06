<?php
/*
 * This file is part of the Twig Bufferized Template package, an RunOpenCode project.
 *
 * (c) 2015 RunOpenCode
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace RunOpenCode\Twig\BufferizedTemplate\Buffer;

final class BufferManager
{
    /**
     * @var BufferQueue
     */
    private $executionQueue;

    /**
     * @var array
     */
    private $renderingQueue;

    /**
     * @var string
     */
    private $output;

    public function __construct()
    {
        $this->executionQueue = new BufferQueue();
        $this->renderingQueue = array();
        $this->output = null;
    }

    /**
     * Add closure to buffer execution queue.
     *
     * @param callable $callable
     * @param int $priority
     */
    public function bufferize(callable $callable, $priority = 0)
    {
        $templateBuffer = new TemplateBuffer($callable, $priority);

        $this->executionQueue->enqueue($templateBuffer);
        $this->renderingQueue[] = $templateBuffer;
    }

    /**
     * Get output.
     *
     * @return string
     */
    public function render()
    {
        return $this->getOutput();
    }

    /**
     * Display output.
     *
     * @return string
     */
    public function display()
    {
        echo $this->getOutput();
    }

    /**
     * Execute buffered templates and get output.
     *
     * @return string
     */
    private function getOutput()
    {
        if (is_null($this->output)) {
            $this->output = '';

            /**
             * @var TemplateBuffer $templateBuffer
             */
            foreach ($templateBuffers = $this->executionQueue as $templateBuffer) {
                $templateBuffer->execute();
            }

            foreach ($templateBuffers = $this->renderingQueue as $renderer) {
                $this->output .= (($renderer instanceof TemplateBuffer) ? $renderer->getOutput() : $renderer);
            }
        }

        return $this->output;
    }
}