<?php

namespace App\Core\Entity;

use Exception;

class ShipsEntity
{
    private array $_ships = [];

    public function __construct(array $ships)
    {
        $this->setShips($ships);
    }

    /**
     * Set the current ships
     *
     * @param array $ships Ships
     *
     * @throws Exception
     *
     * @return void
     */
    private function setShips(array $ships): void
    {
        try {
            if (!is_array($ships)) {
                return;
            }

            $this->_ships = $ships;
        } catch (Exception $e) {
            die('Caught exception: ' . $e->getMessage() . "\n");
        }
    }

    /**
     * Return the ship id
     *
     * @return string
     */
    public function getShipId(): string
    {
        return $this->_ships['ship_id'];
    }

    /**
     * Return the ship planet id
     *
     * @return string
     */
    public function getShipPlanetId(): string
    {
        return $this->_ships['ship_planet_id'];
    }

    /**
     * Return the ship small cargo ship
     *
     * @return string
     */
    public function getShipSmallCargoShip(): string
    {
        return $this->_ships['ship_small_cargo_ship'];
    }

    /**
     * Return the ship big cargo ship
     *
     * @return string
     */
    public function getShipBigCargoShip(): string
    {
        return $this->_ships['ship_big_cargo_ship'];
    }

    /**
     * Return the ship light fighter
     *
     * @return string
     */
    public function getShipLightFighter(): string
    {
        return $this->_ships['ship_light_fighter'];
    }

    /**
     * Return the ship heavy fighter
     *
     * @return string
     */
    public function getShipHeavyFighter(): string
    {
        return $this->_ships['ship_heavy_fighter'];
    }

    /**
     * Return the ship cruiser
     *
     * @return string
     */
    public function getShipCruiser(): string
    {
        return $this->_ships['ship_cruiser'];
    }

    /**
     * Return the ship battleship
     *
     * @return string
     */
    public function getShipBattleship(): string
    {
        return $this->_ships['ship_battleship'];
    }

    /**
     * Return the ship_colony_ship
     *
     * @return string
     */
    public function getShipColonyShip(): string
    {
        return $this->_ships['ship_colony_ship'];
    }

    /**
     * Return the ship_recycler
     *
     * @return string
     */
    public function getShipRecycler(): string
    {
        return $this->_ships['ship_recycler'];
    }

    /**
     * Return the ship espionage probe
     *
     * @return string
     */
    public function getShipEspionageProbe(): string
    {
        return $this->_ships['ship_espionage_probe'];
    }

    /**
     * Return the ship bomber
     *
     * @return string
     */
    public function getShipBomber(): string
    {
        return $this->_ships['ship_bomber'];
    }

    /**
     * Return the ship solar satellite
     *
     * @return string
     */
    public function getShipSolarSatellite(): string
    {
        return $this->_ships['ship_solar_satellite'];
    }

    /**
     * Return the ship destroyer
     *
     * @return string
     */
    public function getShipDestroyer(): string
    {
        return $this->_ships['ship_destroyer'];
    }

    /**
     * Return the ship deathstar
     *
     * @return string
     */
    public function getShipDeathstar(): string
    {
        return $this->_ships['ship_deathstar'];
    }

    /**
     * Return the ship battlecruiser
     *
     * @return string
     */
    public function getShipBattlecruiser(): string
    {
        return $this->_ships['ship_battlecruiser'];
    }
}
