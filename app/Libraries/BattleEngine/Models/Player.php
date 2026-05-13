<?php

namespace App\Libraries\BattleEngine\Models;

use App\Libraries\BattleEngine\CombatObject\FireManager;
use App\Libraries\BattleEngine\Utils\IterableUtil;
use Exception;

/**
 *  OPBE
 *  Copyright (C) 2013  Jstar
 *
 * This file is part of OPBE.
 *
 * OPBE is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * OPBE is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with OPBE.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @package OPBE
 * @author Jstar <frascafresca@gmail.com>
 * @copyright 2013 Jstar <frascafresca@gmail.com>
 * @license http://www.gnu.org/licenses/ GNU AGPLv3 License
 * @version beta(26-10-2013)
 * @link https://github.com/jstar88/opbe
 */
class Player extends IterableUtil
{
    private int $id;
    private int $weapons_tech = 0;
    private int $shields_tech = 0;
    private int $armour_tech = 0;
    private string $name;
    private ?int $galaxy = null;
    private ?int $system = null;
    private ?int $planet = null;

    public function __construct(int $id, array $fleets = [], ?int $weapons_tech = null, ?int $shields_tech = null, ?int $armour_tech = null, string $name = '', ?int $galaxy = null, ?int $system = null, ?int $planet = null)
    {
        $this->id = $id;
        $this->name = $name;
        $this->setTech($weapons_tech, $shields_tech, $armour_tech);
        $this->setCoords($galaxy, $system, $planet);
        foreach ($fleets as $fleet) {
            $this->addFleet($fleet);
        }
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
        foreach ($this->array as $id => $fleet) {
            $fleet->setName($name);
        }
    }

    public function addFleet(Fleet $fleet): void
    {
        $fleet = $fleet->cloneMe();
        $fleet->setTech($this->weapons_tech, $this->shields_tech, $this->armour_tech);
        $fleet->setName($this->name);
        $fleet->setCoords($this->galaxy, $this->system, $this->planet);
        $this->array[$fleet->getId()] = $fleet; //avoid collateral effects: when the object or array is an argument && it's saved in a structure
    }

    public function setTech($weapons = null, $shields = null, $armour = null): void
    {
        foreach ($this->array as $id => $fleet) {
            $fleet->setTech($weapons, $shields, $armour);
        }
        if (is_numeric($weapons)) {
            $this->weapons_tech = intval($weapons);
        }
        if (is_numeric($shields)) {
            $this->shields_tech = intval($shields);
        }
        if (is_numeric($armour)) {
            $this->armour_tech = intval($armour);
        }
    }

    public function setCoords($galaxy = null, $system = null, $planet = null): void
    {
        foreach ($this->array as $id => $fleet) {
            $fleet->setCoords($galaxy, $system, $planet);
        }
        if (is_numeric($galaxy)) {
            $this->galaxy = intval($galaxy);
        }
        if (is_numeric($system)) {
            $this->system = intval($system);
        }
        if (is_numeric($planet)) {
            $this->planet = intval($planet);
        }
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function decrement($idFleet, $idShipType, $count): void
    {
        $this->array[$idFleet]->decrement($idShipType, $count);
        if ($this->array[$idFleet]->isEmpty()) {
            unset($this->array[$idFleet]);
        }
    }

    public function getWeaponsTech(): int
    {
        return $this->weapons_tech;
    }

    public function getShieldsTech(): int
    {
        return $this->shields_tech;
    }

    public function getArmourTech(): int
    {
        return $this->armour_tech;
    }

    public function getGalaxy(): ?int
    {
        return $this->galaxy;
    }

    public function getSystem(): ?int
    {
        return $this->system;
    }

    public function getPlanet(): ?int
    {
        return $this->planet;
    }

    public function getOrderedItereator(): array
    {
        $this->order();

        return $this->array;
    }

    private function order(): void
    {
        if (!ksort($this->array)) {
            throw new Exception('Unable to order fleets');
        }
    }

    public function getFleet($id): Fleet
    {
        return $this->array[$id];
    }

    public function existFleet($idFleet): bool
    {
        return isset($this->array[$idFleet]);
    }

    public function isEmpty(): bool
    {
        foreach ($this->array as $id => $fleet) {
            if (!$fleet->isEmpty()) {
                return false;
            }
        }

        return true;
    }

    public function __toString(): string
    {
        ob_start();
        $_player = $this;
        $_st = '';
        require OPBEPATH . 'Views/player2.html';

        return ob_get_clean();
    }

    public function inflictDamage(FireManager $fire): array
    {
        $physicShots = [];
        foreach ($this->array as $idFleet => $fleet) {
            echo "------- firing to fleet with ID = $idFleet -------- <br>";
            $ps = $fleet->inflictDamage($fire);
            $physicShots[$idFleet] = $ps;
        }

        return $physicShots;
    }

    public function cleanShips(): array
    {
        $shipsCleaners = [];
        foreach ($this->array as $idFleet => $fleet) {
            echo "------- cleanShips to fleet with ID = $idFleet -------- <br>";
            $sc = $fleet->cleanShips();
            $shipsCleaners[$this->getId()] = $sc;
            if ($fleet->isEmpty()) {
                unset($this->array[$idFleet]);
            }
        }

        return $shipsCleaners;
    }

    public function repairShields(): void
    {
        foreach ($this->array as $idFleet => $fleet) {
            $fleet->repairShields();
        }
    }

    public function getEquivalentFleetContent(): Fleet
    {
        $merged = new Fleet(-1);
        foreach ($this->array as $idFleet => $fleet) {
            $merged->mergeFleet($fleet);
        }

        return $merged;
    }

    public function addDefense(Fleet $fleetDefender): void // da fare: controllare ordine
    {
        $fleetDefender = $fleetDefender->cloneMe();
        $fleetDefender->setTech($this->weapons_tech, $this->shields_tech, $this->armour_tech);
        $fleetDefender->setCoords($this->galaxy, $this->system, $this->planet);
        $this->order();
        $fl = current($this->array);
        if ($fl === false) {
            $this->array[$fleetDefender->getId()] = $fleetDefender; //avoid collateral effects: when the object or array is an argument && it's saved in a structure
        } else {
            $fl->mergeFleet($fleetDefender);
        }
    }

    public function mergePlayerFleets(Player $player): void
    {
        foreach ($player->getIterator() as $idFleet => $fleet) {
            $this->array[$idFleet] = $fleet->cloneMe(); //avoid collateral effects: when the object or array is an argument && it's saved in a structure
        }
    }

    public function getTotalCount(): int
    {
        $amount = 0;
        foreach ($this->array as $idFleet => $fleet) {
            $amount += $fleet->getTotalCount();
        }

        return $amount;
    }

    public function cloneMe(): Player
    {
        $fleets = array_values($this->array);

        return new Player(
            $this->id,
            $fleets,
            $this->weapons_tech,
            $this->shields_tech,
            $this->armour_tech,
            $this->name,
            $this->galaxy,
            $this->system,
            $this->planet
        );
    }
}
