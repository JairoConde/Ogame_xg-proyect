<?php

namespace App\Libraries;

class ProductionLib
{
    /**
     * maxStorable
     *
     * @param int $storage_level Storage level
     *
     * @return int
     */
    public static function maxStorable(int $storage_level): int
    {
        $baseStorage = (int) (2.5 * pow(M_E, (20 * ($storage_level) / 33))) * 5000;
        $resourceMultiplier = (float) Functions::readConfig('resource_multiplier');

        return (int) floor($baseStorage * ($resourceMultiplier / 2));
    }

    /**
     * maxProduction
     *
     * @param int $max_energy  Max energy
     * @param int $energy_used Energy used
     *
     * @return int
     */
    public static function maxProduction(int $max_energy, int $energy_used): int
    {
        if (($max_energy == 0) && ($energy_used > 0)) {
            $percentage = 0;
        } elseif (($max_energy > 0) && (($energy_used + $max_energy) < 0)) {
            $percentage = floor(($max_energy) / ($energy_used * -1) * 100);
        } else {
            $percentage = 100;
        }

        if ($percentage > 100) {
            $percentage = 100;
        }

        return $percentage;
    }

    /**
     * productionAmount
     *
     * @param int     $production Production amoint
     * @param int     $boost      Boost by officiers
     * @param int     $mult       Multiplier based on game speed
     * @param boolean $is_energy  Is energy?
     *
     * @return int
     */
    public static function productionAmount($production, $boost, int $mult = 0, bool $is_energy = false): int
    {
        if ($is_energy) {
            return ceil($production * $boost);
        }

        return floor($production * $mult * $boost);
    }

    /**
     * currentProduction
     *
     * @param int $resource       Resource amount
     * @param int $max_production Max production
     *
     * @return int
     */
    public static function currentProduction($resource, $max_production): int
    {
        return (int) round($resource * 0.01 * $max_production);
    }
}
