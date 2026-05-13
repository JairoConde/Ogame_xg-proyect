<?php

namespace App\Libraries\Game;

use App\Core\Entity\AcsFleetEntity;

class AcsFleets
{
    private array $_acs = [];
    private int $_current_user_id = 0;

    public function __construct(array $acs, int $current_user_id)
    {
        if (is_array($acs)) {
            $this->setUp($acs);
            $this->setUserId($current_user_id);
        }
    }

    /**
     * Get all the acs
     *
     * @return array
     */
    public function getAcs(): array
    {
        $list_of_acs = [];

        foreach ($this->_acs as $acs) {
            if (($acs instanceof AcsFleetEntity)) {
                $list_of_acs[] = $acs;
            }
        }

        return $list_of_acs;
    }

    /**
     * Get the first acs result
     *
     * @return ?AcsFleetEntity
     */
    public function getFirstAcs(): ?AcsFleetEntity
    {
        return $this->getAcs()[0] ?? null;
    }

    /**
     * Set up the list of acs
     *
     * @param array $acsFleets Acs Fleets
     *
     * @return void
     */
    private function setUp(array $acsFleets): void
    {
        foreach ($acsFleets as $acs) {
            $data = $this->createNewAcsFleetEntity($acs);

            $this->_acs[] = $data;
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
     *
     * @return int
     */
    private function getUserId(): int
    {
        return $this->_current_user_id;
    }

    /**
     * Create a new instance of AcsFleetEntity
     *
     * @param array $fleet Fleet
     *
     * @return AcsFleetEntity
     */
    private function createNewAcsFleetEntity(array $fleet): AcsFleetEntity
    {
        return new AcsFleetEntity($fleet);
    }
}
