<?php

declare(strict_types=1);

namespace App\Test;

use App\Libraries\Buildings\Queue;
use App\Libraries\Buildings\QueueElements;
use PHPUnit\Framework\TestCase;

/**
 * @covers App\Libraries\Buildings\Queue
 */
class QueueAdvancedTest extends TestCase
{
    private function createQueueElement(
        int $building = 1,
        int $level = 1,
        int $time = 20,
        int $endTime = 1000,
        string $mode = 'build'
    ): QueueElements {
        $element = new QueueElements();
        $element->building = $building;
        $element->build_level = $level;
        $element->build_time = $time;
        $element->build_end_time = $endTime;
        $element->build_mode = $mode;

        return $element;
    }

    public function testQueueCanBeCreatedFromString(): void
    {
        $queue = new Queue('1,1,20,1000,build');
        $elements = $queue->returnQueueAsArray();
        $this->assertCount(1, $elements);
    }

    public function testQueueCanBeCreatedFromArray(): void
    {
        $queue = new Queue(['1,1,20,1000,build']);
        $elements = $queue->returnQueueAsArray();
        $this->assertCount(1, $elements);
    }

    public function testEmptyQueueReturnsEmptyString(): void
    {
        $queue = new Queue();
        $this->assertSame('', $queue->returnQueueAsString());
    }

    public function testEmptyQueueReturnsEmptyArray(): void
    {
        $queue = new Queue();
        $this->assertSame([], $queue->returnQueueAsArray());
    }

    public function testAddAndCountMultipleElements(): void
    {
        $queue = new Queue();
        $queue->addElementToQueue($this->createQueueElement(1, 1, 20, 1000, 'build'));
        $queue->addElementToQueue($this->createQueueElement(2, 3, 60, 2000, 'destroy'));
        $queue->addElementToQueue($this->createQueueElement(3, 5, 120, 3000, 'build'));

        $this->assertSame(3, $queue->countQueueElements());
    }

    public function testRemoveElementFromMiddle(): void
    {
        $queue = new Queue();
        $queue->addElementToQueue($this->createQueueElement(1, 1, 20, 1000, 'build'));
        $queue->addElementToQueue($this->createQueueElement(2, 3, 60, 2000, 'destroy'));
        $queue->addElementToQueue($this->createQueueElement(3, 5, 120, 3000, 'build'));

        $queue->removeElementFromQueue(1);

        $elements = $queue->returnQueueAsArray();
        $this->assertCount(2, $elements);
        // After removal, keys might not be sequential
        $values = array_values($elements);
        $this->assertSame(1, $values[0]['building']);
        $this->assertSame(3, $values[1]['building']);
    }

    public function testGetElementAsArrayReturnsCorrectStructure(): void
    {
        $queue = new Queue();
        $queue->addElementToQueue($this->createQueueElement(5, 2, 45, 1500, 'build'));

        $element = $queue->getElementFromQueueAsArray(0);
        $this->assertIsArray($element);
        $this->assertArrayHasKey('building', $element);
        $this->assertArrayHasKey('build_level', $element);
        $this->assertArrayHasKey('build_time', $element);
        $this->assertArrayHasKey('build_end_time', $element);
        $this->assertArrayHasKey('build_mode', $element);
        $this->assertSame(5, $element['building']);
        $this->assertSame(2, $element['build_level']);
        $this->assertSame('build', $element['build_mode']);
    }

    public function testRemoveElementWithInvalidParameterDoesNothing(): void
    {
        $queue = new Queue();
        $queue->addElementToQueue($this->createQueueElement(1, 1, 10, 500, 'build'));

        $queue->removeElementFromQueue(999);
        $this->assertSame(1, $queue->countQueueElements());
    }

    public function testQueueStringFormat(): void
    {
        $queue = new Queue();
        $queue->addElementToQueue($this->createQueueElement(1, 1, 20, 1000, 'build'));

        $string = $queue->returnQueueAsString();
        $this->assertStringStartsWith('1,1,20,1000,build', $string);
    }

    public function testQueueWithDestroyMode(): void
    {
        $queue = new Queue();
        $queue->addElementToQueue($this->createQueueElement(4, 2, 30, 800, 'destroy'));

        $elements = $queue->returnQueueAsArray();
        $this->assertSame('destroy', $elements[0]['build_mode']);
    }
}
