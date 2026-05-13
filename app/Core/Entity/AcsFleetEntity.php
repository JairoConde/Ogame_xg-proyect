<?php

namespace App\Core\Entity;

use App\Core\Entity;

class AcsFleetEntity extends Entity
{
    public function __construct(array $data)
    {
        parent::__construct($data);
    }

    /**
     * Get the acs id
     *
     * @return string
     */
    public function getAcsFleetId(): string
    {
        return $this->data['acs_id'];
    }

    /**
     * Get the acs name
     *
     * @return string
     */
    public function getAcsFleetName(): string
    {
        return $this->data['acs_name'];
    }

    /**
     * Get the acs owner
     *
     * @return string
     */
    public function getAcsFleetOwner(): string
    {
        return $this->data['acs_owner'];
    }

    /**
     * Get the acs galaxy
     *
     * @return string
     */
    public function getAcsFleetGalaxy(): string
    {
        return $this->data['acs_galaxy'];
    }

    /**
     * Get the acs system
     *
     * @return string
     */
    public function getAcsFleetSystem(): string
    {
        return $this->data['acs_system'];
    }

    /**
     * Get the acs planet
     *
     * @return string
     */
    public function getAcsFleetPlanet(): string
    {
        return $this->data['acs_planet'];
    }

    /**
     * Get the acs planet type
     *
     * @return string
     */
    public function getAcsFleetPlanetType(): string
    {
        return $this->data['acs_planet_type'];
    }
}
