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
 * @copyright 2015 Jstar <frascafresca@gmail.com>
 * @license http://www.gnu.org/licenses/ GNU AGPLv3 License
 * @version 23-3-2015)
 * @link https://github.com/jstar88/opbe
 */
class Fleet extends IterableUtil
{
    private int $count;
    private int $id;
    // added but only used in report templates
    private int $weapons_tech = 0;
    private int $shields_tech = 0;
    private int $armour_tech = 0;
    private string $name;
    private ?int $galaxy;
    private ?int $system;
    private ?int $planet;

    public function __construct(int $id, array $shipTypes = [], ?int $weapons_tech = null, ?int $shields_tech = null, ?int $armour_tech = null, string $name = '', ?int $galaxy = null, ?int $system = null, ?int $planet = null)
    {
        $this->id = $id;
        $this->count = 0;
        $this->name = $name;
        if ($this->id != -1) {
            $this->setTech($weapons_tech, $shields_tech, $armour_tech);
            $this->setCoords($galaxy, $system, $planet);
        }
        foreach ($shipTypes as $shipType) {
            $this->addShipType($shipType);
        }
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function setTech($weapons = null, $shields = null, $armour = null): void
    {
        foreach ($this->array as $id => $shipType) {
            $shipType->setWeaponsTech($weapons);
            $shipType->setShieldsTech($shields);
            $shipType->setArmourTech($armour);
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
        $this->galaxy = $galaxy;
        $this->system = $system;
        $this->planet = $planet;
    }

    public function addShipType(ShipType $shipType): void
    {
        if (isset($this->array[$shipType->getId()])) {
            $this->array[$shipType->getId()]->increment($shipType->getCount());
        } else {
            $shipType = $shipType->cloneMe(); //avoid collateral effects
            if ($this->id != -1) {
                $shipType->setWeaponsTech($this->weapons_tech);
                $shipType->setShieldsTech($this->shields_tech);
                $shipType->setArmourTech($this->armour_tech);
            }
            $this->array[$shipType->getId()] = $shipType;
        }
        $this->count += $shipType->getCount();
    }

    public function decrement($id, $count): void
    {
        $this->array[$id]->decrement($count);
        $this->count -= $count;
        if ($this->array[$id]->getCount() <= 0) {
            unset($this->array[$id]);
        }
    }

    public function mergeFleet(Fleet $other): void
    {
        foreach ($other->getIterator() as $idShipType => $shipType) {
            $this->addShipType($shipType);
        }
    }

    public function getShipType($id): ShipType
    {
        return $this->array[$id];
    }

    public function existShipType($id): bool
    {
        return isset($this->array[$id]);
    }

    public function getTypeCount($type): int
    {
        return $this->array[$type]->getCount();
    }

    public function getTotalCount(): int
    {
        return $this->count;
    }

    public function __toString(): string
    {
        ob_start();
        $_fleet = $this;
        $_st = '';
        require OPBEPATH . 'Views/fleet.html';

        return ob_get_clean();
    }

    public function inflictDamage(FireManager $fires): array
    {
        $physicShots = [];
        //doesn't matter who shot first, but who receive first the damage
        foreach ($fires->getIterator() as $fire) {
            $tmp = [];
            foreach ($this->getOrderedIterator() as $idShipTypeDefender => $shipTypeDefender) {
                $idShipTypeAttacker = $fire->getId();
                log_comment("---- firing from $idShipTypeAttacker to $idShipTypeDefender ----");
                $xs = $fire->getShotsFiredByAllToDefenderType($shipTypeDefender, true);
                $ps = $shipTypeDefender->inflictDamage($fire->getPower(), $xs->result);
                log_var('$xs', $xs);
                $tmp[$idShipTypeDefender] = $xs->rest;
                if ($ps != null) {
                    $physicShots[$idShipTypeDefender][] = $ps;
                }
            }
            log_var('$tmp', $tmp);
            // assign the last shot to the more likely shitType
            $m = 0;
            $f = 0;
            foreach ($tmp as $k => $v) {
                if ($v > $m) {
                    $m = $v;
                    $f = $k;
                }
            }
            if ($f != 0) {
                log_comment('adding 1 shot');
                $ps = $this->getShipType($f)->inflictDamage($fire->getPower(), 1);
                $physicShots[$f][] = $ps;
            }
        }

        return $physicShots;
    }

    public function getOrderedIterator(): array
    {
        if (!ksort($this->array)) {
            throw new Exception('Unable to order types');
        }

        return $this->array;
    }

    public function cleanShips(): array
    {
        $shipsCleaners = [];
        foreach ($this->array as $id => $shipType) {
            log_comment("---- exploding $id ----");
            $sc = $shipType->cleanShips();
            $this->count -= $sc->getExplodedShips();
            if ($shipType->isEmpty()) {
                unset($this->array[$id]);
            }
            $shipsCleaners[$shipType->getId()] = $sc;
        }

        return $shipsCleaners;
    }

    public function repairShields(): void
    {
        foreach ($this->array as $id => $shipTypeDefender) {
            $shipTypeDefender->repairShields();
        }
    }

    public function isEmpty(): bool
    {
        foreach ($this->array as $id => $shipType) {
            if (!$shipType->isEmpty()) {
                return false;
            }
        }

        return true;
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

    public function cloneMe(): Fleet
    {
        $types = array_values($this->array);
        $class = get_class($this);

        return new $class($this->id, $types, $this->weapons_tech, $this->shields_tech, $this->armour_tech, $this->name, $this->galaxy, $this->system, $this->planet);
    }
}
