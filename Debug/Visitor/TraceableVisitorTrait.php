<?php declare(strict_types=1);

namespace JMS\SerializerBundle\Debug\Visitor;

use JMS\Serializer\Metadata\ClassMetadata;
use JMS\Serializer\Metadata\PropertyMetadata;

trait TraceableVisitorTrait
{
    private $inner;
    private $collector;

    private $currentObject;
    /** @var \SplStack */
    private $objectStack;

    private $currentProperty;
    /** @var \SplStack */
    private $propertyStack;

    /** @var \SplStack */
    private $arrayVisitStack;

    private function doVisitArray($data, $type)
    {
        // it is root array
        if (empty($this->currentObject['type'])) {
            $this->currentObject['type'] = ['name' => 'array'];
        }

        $this->arrayVisitStack->push($this->objectStack->count());
        try {
            return $this->inner->visitArray($data, $type);
        } finally {
            $this->arrayVisitStack->pop();
        }
    }

    private function doVisitProperty(PropertyMetadata $metadata, $data)
    {
        $this->propertyStack->push($this->currentProperty);
        $this->currentProperty = $metadata->name;

        $start = microtime(true);
        try {
            return $this->inner->visitProperty($metadata, $data);
        } finally {
            $this->currentObject['properties'][$this->currentProperty]['duration'] = microtime(true) - $start;
            $this->currentObject['properties'][$this->currentProperty]['type'] = $metadata->type ?? ['name' => gettype($data)];

            $this->currentProperty = $this->propertyStack->pop();
        }
    }

    private function doStartVisitingObject(ClassMetadata $metadata, object $data, array $type)
    {
        $this->objectStack->push($this->currentObject);
        $this->currentObject = [
            'start'      => microtime(true),
            'type'       => $type,
            'properties' => [],
            'duration'   => 0,
        ];

        return $this->inner->startVisitingObject($metadata, $data, $type);
    }

    private function doEndVisitingObject(ClassMetadata $metadata, $data, array $type)
    {
        try {
            return $this->inner->endVisitingObject($metadata, $data, $type);
        } finally {
            $this->currentObject['duration'] = microtime(true) - $this->currentObject['start'];

            $child = $this->currentObject;
            $this->currentObject = $this->objectStack->pop();
            $this->placeChild($child);
        }
    }

    private function reset(): void
    {
        assert(empty($this->objectStack) || $this->objectStack->isEmpty());
        assert(empty($this->objectStack) || $this->propertyStack->isEmpty());
        assert(empty($this->objectStack) || $this->arrayVisitStack->isEmpty());

        $this->objectStack = new \SplStack();
        $this->propertyStack = new \SplStack();
        $this->arrayVisitStack = new \SplStack();

        $this->currentObject = [
            'properties' => [],
            'duration'   => 0,
        ];

        $this->currentProperty = null;
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
            $this->currentObject['properties'][$this->currentProperty] = $child;
        } elseif (0 === $this->objectStack->count()) {
            // object is root
            $this->currentObject = $child;
        }
    }
}