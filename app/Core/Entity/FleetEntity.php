<?php

namespace App\Core\Entity;

use App\Core\Entity;

class FleetEntity extends Entity
{
    public function __construct(array $data)
    {
        parent::__construct($data);
    }

    /**
     * Get the fleet id
     *
     * @return string
     */
    public function getFleetId(): string
    {
        return $this->data['fleet_id'];
    }

    /**
     * Get the fleet owner
     *
     * @return string
     */
    public function getFleetOwner(): string
    {
        return $this->data['fleet_owner'];
    }

    /**
     * Get the fleet mission
     *
     * @return string
     */
    public function getFleetMission(): string
    {
        return $this->data['fleet_mission'];
    }

    /**
     * Get the fleet amount
     *
     * @return string
     */
    public function getFleetAmount(): string
    {
        return $this->data['fleet_amount'];
    }

    /**
     * Get the fleet array
     *
     * @return string
     */
    public function getFleetArray(): string
    {
        return $this->data['fleet_array'];
    }

    /**
     * Get the fleet start time
     *
     * @return string
     */
    public function getFleetStartTime(): string
    {
        return $this->data['fleet_start_time'];
    }

    /**
     * Get the fleet start galaxy
     *
     * @return string
     */
    public function getFleetStartGalaxy(): string
    {
        return $this->data['fleet_start_galaxy'];
    }

    /**
     * Get the fleet start system
     *
     * @return string
     */
    public function getFleetStartSystem(): string
    {
        return $this->data['fleet_start_system'];
    }

    /**
     * Get the fleet start planet
     *
     * @return string
     */
    public function getFleetStartPlanet(): string
    {
        return $this->data['fleet_start_planet'];
    }

    /**
     * Get the fleet start type
     *
     * @return string
     */
    public function getFleetStartType(): string
    {
        return $this->data['fleet_start_type'];
    }

    /**
     * Get the fleet end time
     *
     * @return string
     */
    public function getFleetEndTime(): string
    {
        return $this->data['fleet_end_time'];
    }

    /**
     * Get the fleet end stay
     *
     * @return string
     */
    public function getFleetEndStay(): string
    {
        return $this->data['fleet_end_stay'];
    }

    /**
     * Get the fleet end galaxy
     *
     * @return string
     */
    public function getFleetEndGalaxy(): string
    {
        return $this->data['fleet_end_galaxy'];
    }

    /**
     * Get the fleet end system
     *
     * @return string
     */
    public function getFleetEndSystem(): string
    {
        return $this->data['fleet_end_system'];
    }

    /**
     * Get the fleet end planet
     *
     * @return string
     */
    public function getFleetEndPlanet(): string
    {
        return $this->data['fleet_end_planet'];
    }

    /**
     * Get the fleet end type
     *
     * @return string
     */
    public function getFleetEndType(): string
    {
        return $this->data['fleet_end_type'];
    }

    /**
     * Get the fleet target obj
     *
     * @return string
     */
    public function getFleetTargetObj(): string
    {
        return $this->data['fleet_target_obj'];
    }

    /**
     * Get the fleet resource metal
     *
     * @return string
     */
    public function getFleetResourceMetal(): string
    {
        return $this->data['fleet_resource_metal'];
    }

    /**
     * Get the fleet resource crystal
     *
     * @return string
     */
    public function getFleetResourceCrystal(): string
    {
        return $this->data['fleet_resource_crystal'];
    }

    /**
     * Get the fleet resource deuterium
     *
     * @return string
     */
    public function getFleetResourceDeuterium(): string
    {
        return $this->data['fleet_resource_deuterium'];
    }

    /**
     * Get the fleet fuel
     *
     * @return string
     */
    public function getFleetFuel(): string
    {
        return $this->data['fleet_fuel'];
    }

    /**
     * Get the fleet target owner
     *
     * @return string
     */
    public function getFleetTargetOwner(): string
    {
        return $this->data['fleet_target_owner'];
    }

    /**
     * Get the fleet group
     *
     * @return string
     */
    public function getFleetGroup(): string
    {
        return $this->data['fleet_group'];
    }

    /**
     * Get the fleet mess
     *
     * @return string
     */
    public function getFleetMess(): string
    {
        return $this->data['fleet_mess'];
    }

    /**
     * Get the fleet creation
     *
     * @return string
     */
    public function getFleetCreation(): string
    {
        return $this->data['fleet_creation'];
    }
}
