<?php

namespace App\Libraries\Missions;

use App\Libraries\BattleEngine\Utils\Lang;

class AttackLang implements Lang
{
    private mixed $lang = null;
    private mixed $objects = null;

    public function __construct(mixed $lang, mixed $objects)
    {
        $this->lang = $lang;
        $this->objects = $objects;
    }

    /**
     * getShipName
     *
     * @param int $id ID
     *
     * @return string
     */
    public function getShipName(int $id): string
    {
        $shipName = $this->objects[$id] ?? 'Unknown';

        return $this->lang->language[$shipName] ?? $shipName . ' (' . $id . ')';
    }

    /**
     * getAttackersAttackingDescr
     *
     * @param int $amount Amount
     * @param int $damage Damage
     *
     * @return string
     */
    public function getAttackersAttackingDescr(int $amount, int $damage): string
    {
        return sprintf($this->lang->line('cr_fleet_attack_1'), $amount, $damage);
    }

    /**
     * getDefendersDefendingDescr
     *
     * @param int $damage Damage
     *
     * @return string
     */
    public function getDefendersDefendingDescr(int $damage): string
    {
        return sprintf($this->lang->line('cr_fleet_attack_2'), $damage);
    }

    /**
     * getDefendersAttackingDescr
     *
     * @param int $amount Amount
     * @param int $damage Damage
     *
     * @return string
     */
    public function getDefendersAttackingDescr(int $amount, int $damage): string
    {
        return sprintf($this->lang->line('cr_fleet_defs_1'), $amount, $damage);
    }

    /**
     * getAttackersDefendingDescr
     *
     * @param int $damage Damage
     *
     * @return string
     */
    public function getAttackersDefendingDescr(int $damage): string
    {
        return sprintf($this->lang->line('cr_fleet_defs_2'), $damage);
    }

    /**
     * getTechs
     *
     * @param type $weaponsTech
     * @param type $shieldsTech
     * @param type $armourTech
     *
     * @return string
     */
    public function getTechs(mixed $weaponsTech, mixed $shieldsTech, mixed $armourTech): string
    {
        return sprintf($this->lang->line('cr_technologies'), ($weaponsTech * 10), ($shieldsTech * 10), ($armourTech * 10));
    }

    /**
     * getAttackerHasWon
     *
     * @return string
     */
    public function getAttackerHasWon(): string
    {
        return $this->lang->line('cr_attacker_won');
    }

    /**
     * getDefendersHasWon
     *
     * @return string
     */
    public function getDefendersHasWon(): string
    {
        return $this->lang->line('cr_defender_won');
    }

    /**
     * getDraw
     *
     * @return string
     */
    public function getDraw(): string
    {
        return $this->lang->line('cr_both_won');
    }

    /**
     * getStoleDescr
     *
     * @param int $metal     Metal
     * @param int $crystal   Crystal
     * @param int $deuterium Deuterium
     *
     * @return string
     */
    public function getStoleDescr(int|string $metal, int|string $crystal, int|string $deuterium): string
    {
        return sprintf($this->lang->line('cr_stealed_ressources'), $metal, $crystal, $deuterium);
    }

    /**
     * getAttackersLostUnits
     *
     * @param int $units Units
     *
     * @return string
     */
    public function getAttackersLostUnits(int $units): string
    {
        return sprintf($this->lang->line('cr_attacker_lostunits'), $units);
    }

    /**
     * getDefendersLostUnits
     *
     * @param int $units Units
     *
     * @return string
     */
    public function getDefendersLostUnits(int $units): string
    {
        return sprintf($this->lang->line('cr_defender_lostunits'), $units);
    }

    /**
     * getFloatingDebris
     *
     * @param int $metal   Metal
     * @param int $crystal Crystal
     *
     * @return string
     */
    public function getFloatingDebris(int $metal, int $crystal): string
    {
        return sprintf($this->lang->line('cr_debris_units'), $metal, $crystal);
    }

    /**
     * getMoonProb
     *
     * @param int $prob Probability
     *
     * @return string
     */
    public function getMoonProb(int $prob): string
    {
        return sprintf($this->lang->line('cr_moonproba'), $prob);
    }

    /**
     * getNewMoon
     *
     * @param string $name
     * @param int    $galaxy
     * @param int    $system
     * @param int    $planet
     *
     * @return string
     */
    public function getNewMoon(string $name, int $galaxy, int $system, int $planet): string
    {
        return sprintf($this->lang->line('cr_moonbuilt'), $name, $galaxy, $system, $planet);
    }
}
