<?php declare(strict_types=1);

namespace JMS\SerializerBundle\Debug;

use JMS\Serializer\Metadata\ClassMetadata;
use JMS\Serializer\Metadata\PropertyMetadata;

final class RunsCollector
{
    /** @var array */
    private $runs = [];

    private $currentObject;
    /** @var \SplStack */
    private $objectStack;

    private $currentProperty;
    /** @var \SplStack */
    private $propertyStack;

    /** @var \SplStack */
    private $arrayVisitStack;

    /** @var \SplStack */
    private $propertyTimerStack;

    /** @var \SplStack */
    private $handlerStack;

    /** @var \SplStack */
    private $eventListenerStack;

    public function startVisitingArray($data, $type): void
    {
        // it is root array
        if (empty($this->currentObject['type'])) {
            $this->currentObject['type'] = ['name' => 'array'];
        }

        $this->arrayVisitStack->push($this->objectStack->count());
    }

    public function endVisitingArray(): void
    {
        $this->arrayVisitStack->pop();
    }

    public function startVisitingProperty(PropertyMetadata $metadata, $data): void
    {
        $this->propertyStack->push($this->currentProperty);
        $this->currentProperty = $metadata->name;

        $this->propertyTimerStack->push(microtime(true));
    }

    public function endVisitingProperty(PropertyMetadata $metadata, $data): void
    {
        assert($this->propertyTimerStack->count() > 0);

        $this->currentObject['properties'][$this->currentProperty]['duration'] = microtime(true) - $this->propertyTimerStack->pop();
        $this->currentObject['properties'][$this->currentProperty]['type'] = $metadata->type ?? ['name' => gettype($data)];

        $this->currentProperty = $this->propertyStack->pop();
    }

    public function startVisitingObject(ClassMetadata $metadata, object $data, array $type): void
    {
        $this->objectStack->push($this->currentObject);
        $this->currentObject = [
            'start'      => microtime(true),
            'type'       => $type,
            'properties' => [],
            'duration'   => 0,
        ];
    }

    public function endVisitingObject(ClassMetadata $metadata, $data, array $type): void
    {
        $this->currentObject['duration'] = microtime(true) - $this->currentObject['start'];

        $child = $this->currentObject;
        $this->currentObject = $this->objectStack->pop();
        $this->placeChild($child);
    }

    public function getRuns(): array
    {
        return $this->runs;
    }

    public function start(): void
    {
        assert(empty($this->objectStack) || $this->objectStack->isEmpty());
        assert(empty($this->propertyStack) || $this->propertyStack->isEmpty());
        assert(empty($this->arrayVisitStack) || $this->arrayVisitStack->isEmpty());
        assert(empty($this->propertyTimerStack) || $this->propertyTimerStack->isEmpty());
        assert(empty($this->handlerStack) || $this->handlerStack->isEmpty());
        assert(empty($this->eventListenerStack) || $this->eventListenerStack->isEmpty());

        $this->reset();
    }

    public function end(int $direction, string $format): void
    {
        $this->runs[] = array_merge($this->currentObject, [
            'direction' => $direction,
            'format'    => $format,
        ]);

        $this->reset();
    }

    private function withinArray(): bool
    {
        if (0 === $this->arrayVisitStack->count()) {
            return false;
        }

        return $this->objectStack->count() === $this->arrayVisitStack->top();
    }

    private function placeChild(array $child): void
    {
        if ($this->withinArray()) {
            if ($this->currentProperty) {
                $this->currentObject['properties'][$this->currentProperty]['properties'][] = $child;
            } else {
                // array is root
                $this->currentObject['properties'][] = $child;
                $this->currentObject['duration'] += $child['duration'];
            }
        } elseif ($this->currentProperty) {
            $this->currentObject['properties'][$this->currentProperty] = array_merge(
                $this->currentObject['properties'][$this->currentProperty] ?? [],
                $child
            );
        } elseif (0 === $this->objectStack->count()) {
            // object is root
            $this->currentObject = $child;
        }
    }

    private function reset(): void
    {
        $this->objectStack = new \SplStack();
        $this->propertyStack = new \SplStack();
        $this->arrayVisitStack = new \SplStack();
        $this->propertyTimerStack = new \SplStack();
        $this->handlerStack = new \SplStack();
        $this->eventListenerStack = new \SplStack();

        $this->currentObject = [
            'duration'   => 0,
            'properties' => [],
            'handlers'   => [],
        ];

        $this->currentProperty = null;
    }

    public function startHandler(string $handlerClass): float
    {
        $this->handlerStack->push([
            'class' => $handlerClass,
            'start' => $start = microtime(true),
        ]);

        return $start;
    }

    public function endHandler(): float
    {
        assert(!empty($this->currentProperty));
        assert($this->handlerStack->count() > 0);

        $handlerTrace = $this->handlerStack->pop();
        $handlerTrace['duration'] = microtime(true) - $handlerTrace['start'];

        $this->currentObject['properties'][$this->currentProperty]['handlers'][] = $handlerTrace;

        return $handlerTrace['duration'];
    }

    public function startEventListener(string $event, string $listenerClass, string $method): float
    {
        $this->eventListenerStack->push([
            'event'  => $event,
            'class'  => $listenerClass,
            'method' => $method,
            'start'  => $start = microtime(true),
        ]);

        return $start;
    }

    public function endEventListener(string $event, string $listenerClass, string $method): float
    {
        assert($this->eventListenerStack->count() > 0);

        $elTrace = $this->eventListenerStack->pop();
        $elTrace['duration'] = microtime(true) - $elTrace['start'];

        if ($this->currentProperty) {
            $this->currentObject['properties'][$this->currentProperty]['eventListeners'][] = $elTrace;
        } else {
            $this->currentObject['eventListeners'][] = $elTrace;
        }

        return $elTrace['duration'];
    }
}