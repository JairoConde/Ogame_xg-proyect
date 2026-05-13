<?php

namespace App\Libraries\Buildings;

use App\Core\Objects;
use App\Libraries\DevelopmentsLib;
use App\Libraries\OfficiersLib;

class Building
{
    private Queue $_queue;
    private array $_planet = [];
    private array $_user = [];
    private Objects $_objects;
    private int $_building = 0;
    private int $_build_level = 0;
    private int $_build_time = 0;

    /**
     * Init the class with some values
     *
     * @param array   $planet  Planet
     * @param array   $user    User
     * @param Objects $objects Objects
     */
    public function __construct(array $planet, array $user, Objects $objects)
    {
        $this->_queue = new Queue($planet['planet_b_building_id']);
        $this->_planet = $planet;
        $this->_user = $user;
        $this->_objects = $objects;
    }

    /**
     * Add a new building to the queue, that will be build
     *
     * @param int $building_id Building ID
     *
     * @return void
     */
    public function addBuilding(int $building_id): void
    {
        $this->_building = $building_id;

        $this->queueElementToBuild();
    }

    /**
     * Remove building from list
     *
     * @param int $element_id
     *
     * @return void
     */
    public function removeBuilding(int $element_id): void
    {
        $this->removeElementFromBuildingQueue($element_id);
    }

    /**
     * Cancel current building
     *
     * @return void
     */
    public function cancelBuilding(): void
    {
        $this->removeFirstElementFromBuildingQueue();
    }

    /**
     * Add a new building to the queue, that will be destroyed
     *
     * @param int $building_id Building ID
     *
     * @return void
     */
    public function tearDownBuilding(int $building_id): void
    {
        $this->_building = $building_id;

        $this->queueElementToTearDown();
    }

    /**
     * Count current elements in the queue
     *
     * @return int
     */
    public function getCountElementsOnQueue(): int
    {
        return $this->_queue->countQueueElements();
    }

    /**
     * Get the updated queue as a string
     *
     * @return string
     */
    public function getNewQueueAsString(): string
    {
        return $this->_queue->returnQueueAsString();
    }

    /**
     * Get the updated queue as an array
     *
     * @return array
     */
    public function getNewQueueAsArray(): array
    {
        return $this->_queue->returnQueueAsArray();
    }

    /**
     * Check if the queue is full
     *
     * @return boolean
     */
    public function isQueueFull(): bool
    {
        $queue_size = 1;

        if (OfficiersLib::isOfficierActive($this->_user['premium_officier_commander'])) {
            $queue_size = MAX_BUILDING_QUEUE_SIZE;
        }

        return !($this->getCountElementsOnQueue() < $queue_size);
    }

    /**
     * Create a new QueueElements block
     *
     * @param string $build_mode Build mode
     *
     * @return QueueElements
     */
    private function buildQueueElementsBlock(string $build_mode): ?QueueElements
    {
        $build_level = $this->calculateBuildLevel($build_mode);

        if ($build_level < 0) {
            return null;
        }

        $queue_elements = new QueueElements();
        $queue_elements->building = $this->_building;
        $queue_elements->build_level = $build_level;
        $queue_elements->build_time = $this->calculateBuildTime($build_mode);
        $queue_elements->build_end_time = $this->calculateBuildEndTime();
        $queue_elements->build_mode = $build_mode;

        return $queue_elements;
    }

    /**
     * Queue an element to be build
     *
     * @return void
     */
    private function queueElementToBuild(): void
    {
        $this->_queue->addElementToQueue(
            $this->buildQueueElementsBlock('build')
        );
    }

    /**
     * Queue an element to tear down
     *
     * @return string
     */
    private function queueElementToTearDown(): string
    {
        $this->_queue->addElementToQueue(
            $this->buildQueueElementsBlock('teardown')
        );

        return $this->_queue->returnQueueAsString();
    }

    /**
     * Remove an element from the queue
     *
     * @return void
     */
    private function removeElementFromBuildingQueue(int $element_id): void
    {
        $this->_queue->removeElementFromQueue($element_id);
    }

    /**
     * Remove the first element from the queue, cancel action
     *
     * @return void
     */
    private function removeFirstElementFromBuildingQueue(): void
    {
        $this->removeElementFromBuildingQueue(0);
    }

    /**
     * Get building current level
     *
     * @return int
     */
    private function getBuildingCurrentLevel(): int
    {
        return $this->_planet[$this->_objects->getObjects($this->_building)];
    }

    /**
     * Set the level based on if we are going to build or destroy
     *
     * @param string $build_mode Build Mode
     *
     * @return int
     */
    private function calculateBuildLevel(string $build_mode): int
    {
        $difference = ($build_mode == 'teardown') ? -1 : 1;

        return $this->getBuildingCurrentLevel() + $difference;
    }

    /**
     * Set the time based on if we are going to build or destroy
     *
     * @param string $build_mode Build Mode
     *
     * @return int
     */
    private function calculateBuildTime(string $build_mode): int
    {
        $difference = ($build_mode == 'teardown') ? 2 : 1;

        $this->_build_time = DevelopmentsLib::developmentTime(
            $this->_user,
            $this->_planet,
            $this->_building
        ) / $difference;

        return $this->_build_time;
    }

    /**
     * Calculate the building time for each element
     * depending if it's the first element in the queue
     * or there's something before.
     *
     * @return int
     */
    private function calculateBuildEndTime(): int
    {
        if ($this->getCountElementsOnQueue() <= 0) {
            return time() + $this->_build_time;
        } else {
            $prev_element = $this->getCountElementsOnQueue() - 1;
            $prev_element_time = $this->_queue->getElementFromQueueAsArray($prev_element)[2];

            return (int) $prev_element_time + $this->_build_time;
        }
    }
}
