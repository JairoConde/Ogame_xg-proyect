<?php

namespace App\Libraries\Game;

use App\Core\Entity\FleetEntity;
use App\Core\Enumerators\MissionsEnumerator as Missions;

class Fleets
{
    private array $_fleets = [];
    private int $_current_user_id = 0;
    private int $_fleet_count = 0;
    private int $_expedition_count = 0;
    private array $_fleets_index = [];

    public function __construct(array $fleets, int $current_user_id)
    {
        if (is_array($fleets)) {
            $this->setUp($fleets);
            $this->setUserId($current_user_id);
        }
    }

    /**
     * Get all the fleets
     *
     * @return array
     */
    public function getFleets(): array
    {
        $list_of_fleets = [];
        $index = 0;

        foreach ($this->_fleets as $fleets) {
            if (($fleets instanceof FleetEntity)) {
                $list_of_fleets[] = $fleets;
            }
        }

        return $list_of_fleets;
    }

    /**
     * Get a fleet by ID
     *
     * @param int $fleet_id
     *
     * @return FleetEntity
     */
    public function getFleetById(int $fleet_id): FleetEntity
    {
        return $this->_fleets[$this->validateIndex($fleet_id)] ?? new FleetEntity([]);
    }

    /**
     * Get a fleet by ID
     *
     * @param int $fleet_id
     *
     * @return FleetEntity
     */
    public function getOwnFleetById(int $fleet_id): ?FleetEntity
    {
        $fleet = $this->getFleetById($fleet_id);

        if ($fleet->getFleetOwner() == $this->getUserId()) {
            return $fleet;
        }

        return null;
    }

    /**
     * Get a valid fleet by ID
     *
     * @param int $fleet_id
     *
     * @return FleetEntity
     */
    public function getOwnValidFleetById(int $fleet_id): ?FleetEntity
    {
        $fleet = $this->getOwnFleetById($fleet_id);

        if ($fleet->getFleetStartTime() <= time()
            or $fleet->getFleetEndTime() < time()
            or $fleet->getFleetMess() == 1) {
            return null;
        }

        return $fleet;
    }

    /**
     * Validate index
     *
     * @param int $fleet_id
     *
     * @return int
     */
    private function validateIndex(int $fleet_id): int
    {
        return isset($this->_fleets_index[$fleet_id]) ? $this->_fleets_index[$fleet_id] : -1;
    }

    /**
     * Set up the list of fleets
     *
     * @param array $fleets Fleets
     *
     * @return void
     */
    private function setUp(array $fleets): void
    {
        $index = 0;

        foreach ($fleets as $fleet) {
            $data = $this->createNewFleetEntity($fleet);

            $this->_fleets[] = $data;
            $this->_fleets_index[$data->getFleetId()] = $index++;

            $this->setFleetsCount();

            if ($data->getFleetMission() == Missions::EXPEDITION) {
                $this->setExpeditionsCount();
            }
        }
    }

    /**
     *
     * @param int $user_id User Id
     */
    private function setUserId(int $user_id): void
    {
        $this->_current_user_id = $user_id;
    }

    /**
     * Increase the fleets count
     *
     * @return void
     */
    private function setFleetsCount(): void
    {
        $this->_fleet_count++;
    }

    /**
     * Increase the expeditions count
     *
     * @return void
     */
    private function setExpeditionsCount(): void
    {
        $this->_expedition_count++;
    }

    /**
     *
     * @return int
     */
    private function getUserId(): int
    {
        return $this->_current_user_id;
    }

    /**
     *
     * @return int
     */
    public function getFleetsCount(): int
    {
        return $this->_fleet_count;
    }

    /**
     *
     * @return int
     */
    public function getExpeditionsCount(): int
    {
        return $this->_expedition_count;
    }

    /**
     * Create a new instance of FleetEntity
     *
     * @param array $fleet Fleet
     *
     * @return FleetEntity
     */
    private function createNewFleetEntity(array $fleet): FleetEntity
    {
        return new FleetEntity($fleet);
    }
}
