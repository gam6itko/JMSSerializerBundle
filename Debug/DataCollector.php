<?php

declare(strict_types=1);

namespace JMS\SerializerBundle\Debug;

use JMS\SerializerBundle\Debug\EventDispatcher\TraceableEventDispatcher;
use JMS\SerializerBundle\Debug\Handler\TraceableHandlerRegistry;
use JMS\SerializerBundle\Debug\RunsCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector as BaseDataCollector;
use Symfony\Component\HttpKernel\DataCollector\LateDataCollectorInterface;

final class DataCollector extends BaseDataCollector implements LateDataCollectorInterface
{
    private $visitorTracesCollector;
    private $eventDispatcher;
    private $handler;

    public function __construct(RunsCollector $visitorTracesCollector, TraceableEventDispatcher $eventDispatcher, TraceableHandlerRegistry $handler)
    {
        $this->visitorTracesCollector = $visitorTracesCollector;
        $this->eventDispatcher = $eventDispatcher;
        $this->handler = $handler;

        $this->reset();
    }

    public function collect(Request $request, Response $response, \Throwable $exception = null)
    {
    }

    public function reset(): void
    {
        $this->stack = new \SplStack();
        $this->data['handlers'] = [];
        $this->data['metadata'] = [];
        $this->data['listeners'] = [];
    }

    public function getName(): string
    {
        return 'jms_serializer';
    }

    public function addTriggeredEvent(array $call): void
    {
        $this->data['listeners'][] = $call;
    }

    public function getTriggeredListeners()
    {
        return $this->data['listeners']['called'];
    }

    public function getNotTriggeredListeners()
    {
        return $this->data['listeners']['not_called'];
    }

    public function getTriggeredHandlers()
    {
        return $this->data['handlers']['called'];
    }

    public function getNotTriggeredHandlers()
    {
        return $this->data['handlers']['not_called'];
    }

    public function addMetadataLoad(string $class, string $loader, $loaded)
    {
        $this->data['metadata'][$class][$loader]['result'] = !!$loaded;
    }

    public function getLoadedMetadata()
    {
        return $this->data['metadata'];
    }

    public function getTotalDuration()
    {
        return 0;
    }

    public function getRuns(): array
    {
        return $this->data['runs'];
    }

    /**
     * @return float|int In milliseconds
     */
    public function getRunsDuration()
    {
        if (empty($this->data['runs'])) {
            return 0;
        }

        return array_sum(array_column($this->data['runs'], 'duration')) * 1000;
    }

    public function lateCollect()
    {
        $this->data['runs'] = $this->visitorTracesCollector->getRuns();

        $this->data['listeners'] = [
            'called'     => $this->eventDispatcher->getTriggeredListeners(),
            'not_called' => $this->eventDispatcher->getNotTriggeredListeners(),
        ];

        $this->data['handlers'] = [
            'called'     => $this->handler->getTriggeredHandlers(),
            'not_called' => $this->handler->getNotTriggeredHandlers(),
        ];
    }
}
