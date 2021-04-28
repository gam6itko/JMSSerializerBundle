<?php declare(strict_types=1);

namespace JMS\SerializerBundle\Debug\Visitor;

final class VisitorTracesCollector
{
    /** @var array */
    private $runs = [];

    public function addRun(int $direction, string $format, array $run): void
    {
        $this->runs[] = array_merge([
            'direction' => $direction,
            'format'    => $format,
        ], $run);
    }

    public function getRuns(): array
    {
        return $this->runs;
    }
}