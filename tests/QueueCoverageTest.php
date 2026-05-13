<?php

declare(strict_types=1);

namespace App\Test;

use App\Libraries\Buildings\Queue;
use App\Libraries\Buildings\QueueElements;
use PHPUnit\Framework\TestCase;

/**
 * @covers App\Libraries\Buildings\Queue
 */
class QueueCoverageTest extends TestCase
{
    /**
     * Helper to invoke a private/protected method via reflection.
     *
     * @return mixed
     */
    private function invokePrivateMethod(Queue $instance, string $methodName, array $args = [])
    {
        $reflection = new \ReflectionMethod(Queue::class, $methodName);
        $reflection->setAccessible(true);

        return $reflection->invoke($instance, ...$args);
    }

    /**
     * Helper to set a property on the instance via reflection.
     */
    private function setProperty(Queue $instance, string $property, $value): void
    {
        $reflection = new \ReflectionProperty($instance, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($instance, $value);
    }

    /**
     * Helper to get a property value from the instance via reflection.
     *
     * @return mixed
     */
    private function getProperty(Queue $instance, string $property)
    {
        $reflection = new \ReflectionProperty($instance, $property);
        $reflection->setAccessible(true);

        return $reflection->getValue($instance);
    }

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

    // --- breakDownCurrentQueue tests ---

    public function testBreakDownCurrentQueueConvertsStringToArray(): void
    {
        $queue = new Queue('1,1,20,1000,build');
        $this->assertIsString($this->getProperty($queue, 'queue'));

        $this->invokePrivateMethod($queue, 'breakDownCurrentQueue');

        $result = $this->getProperty($queue, 'queue');
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertSame(['1', '1', '20', '1000', 'build'], $result[0]);
    }

    public function testBreakDownCurrentQueueHandlesMultipleElements(): void
    {
        $queue = new Queue('1,1,20,1000,build;2,3,60,2000,destroy');

        $this->invokePrivateMethod($queue, 'breakDownCurrentQueue');

        $result = $this->getProperty($queue, 'queue');
        $this->assertCount(2, $result);
        $this->assertSame(['1', '1', '20', '1000', 'build'], $result[0]);
        $this->assertSame(['2', '3', '60', '2000', 'destroy'], $result[1]);
    }

    public function testBreakDownCurrentQueueFiltersEmptyElements(): void
    {
        $queue = new Queue('1,1,20,1000,build;;2,3,60,2000,destroy;');

        $this->invokePrivateMethod($queue, 'breakDownCurrentQueue');

        $result = $this->getProperty($queue, 'queue');
        $this->assertCount(2, $result);
    }

    public function testBreakDownCurrentQueueOnEmptyString(): void
    {
        $queue = new Queue('');

        $this->invokePrivateMethod($queue, 'breakDownCurrentQueue');

        $result = $this->getProperty($queue, 'queue');
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // --- makeUpCurrentQueue tests ---

    public function testMakeUpCurrentQueueConvertsArrayToString(): void
    {
        $queue = new Queue();
        $this->setProperty($queue, 'queue', [
            ['1', '1', '20', '1000', 'build'],
        ]);

        $this->invokePrivateMethod($queue, 'makeUpCurrentQueue');

        $result = $this->getProperty($queue, 'queue');
        $this->assertIsString($result);
        $this->assertSame('1,1,20,1000,build', $result);
    }

    public function testMakeUpCurrentQueueHandlesMultipleElements(): void
    {
        $queue = new Queue();
        $this->setProperty($queue, 'queue', [
            ['1', '1', '20', '1000', 'build'],
            ['2', '3', '60', '2000', 'destroy'],
        ]);

        $this->invokePrivateMethod($queue, 'makeUpCurrentQueue');

        $result = $this->getProperty($queue, 'queue');
        $this->assertIsString($result);
        $this->assertSame('1,1,20,1000,build;2,3,60,2000,destroy', $result);
    }

    public function testMakeUpCurrentQueueHandlesEmptyArray(): void
    {
        $queue = new Queue();
        $this->setProperty($queue, 'queue', []);

        $this->invokePrivateMethod($queue, 'makeUpCurrentQueue');

        $result = $this->getProperty($queue, 'queue');
        $this->assertIsString($result);
        $this->assertSame('', $result);
    }

    // --- Edge cases for existing public methods ---

    public function testCountQueueElementsOnStringQueueTriggersBreakDown(): void
    {
        $queue = new Queue('1,1,20,1000,build;2,3,60,2000,destroy');

        $count = $queue->countQueueElements();
        $this->assertSame(2, $count);

        $this->assertIsArray($this->getProperty($queue, 'queue'));
    }

    public function testAddElementToQueueForcesBreakDownWhenQueueIsString(): void
    {
        $queue = new Queue('1,1,20,1000,build');
        $this->assertIsString($this->getProperty($queue, 'queue'));

        $queue->addElementToQueue($this->createQueueElement(2, 3, 60, 2000, 'destroy'));

        $this->assertIsArray($this->getProperty($queue, 'queue'));
        $this->assertCount(2, $queue->returnQueueAsArray());
    }

    public function testReturnQueueAsStringWithArrayInputTriggersMakeUp(): void
    {
        $queue = new Queue('1,1,20,1000,build');
        $queue->addElementToQueue($this->createQueueElement(2, 3, 60, 2000, 'destroy'));

        $string = $queue->returnQueueAsString();
        $this->assertIsString($string);
        $this->assertStringContainsString('1,1,20,1000,build', $string);
        $this->assertStringContainsString('2,3,60,2000,destroy', $string);
    }

    public function testRemoveElementFromQueueWithNonExistentKeyDoesNothing(): void
    {
        $queue = new Queue();
        $queue->addElementToQueue($this->createQueueElement(1, 1, 10, 500, 'build'));

        $queue->removeElementFromQueue(99);
        $this->assertSame(1, $queue->countQueueElements());
    }

    public function testGetElementFromQueueAsArrayReturnsEmptyForNonExistentKey(): void
    {
        $queue = new Queue();
        $queue->addElementToQueue($this->createQueueElement(1, 1, 10, 500, 'build'));

        // Use reflection to get the element directly and test the array
        $array = $queue->returnQueueAsArray();
        $this->assertIsArray($array);
        $this->assertArrayNotHasKey(99, $array);
    }

    public function testReturnQueueAsArrayOnEmptyQueue(): void
    {
        $queue = new Queue();
        $this->assertSame([], $queue->returnQueueAsArray());
    }

    public function testQueueConstructorWithArrayInput(): void
    {
        $queue = new Queue(['1,1,20,1000,build', '2,3,60,2000,destroy']);

        $result = $queue->returnQueueAsArray();
        $this->assertCount(2, $result);
    }

    // --- Roundtrip tests (string -> array -> back to string) ---

    public function testFullRoundtripStringToArrayToString(): void
    {
        $original = '1,1,20,1000,build;2,3,60,2000,destroy';
        $queue = new Queue($original);

        $array = $queue->returnQueueAsArray();
        $this->assertCount(2, $array);

        $backToString = $queue->returnQueueAsString();
        $this->assertSame($original, $backToString);
    }

    public function testRemoveElementThenReturnAsString(): void
    {
        $queue = new Queue('1,1,20,1000,build;2,3,60,2000,destroy;3,5,120,3000,build');

        $queue->removeElementFromQueue(1); // remove middle element

        $string = $queue->returnQueueAsString();
        $this->assertIsString($string);
        $this->assertStringNotContainsString('2,3,60,2000,destroy', $string);
    }
}
