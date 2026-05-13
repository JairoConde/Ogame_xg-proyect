<?php

namespace App\Libraries;

use App\Core\Enumerators\BuildingsEnumerator as Buildings;

abstract class Formulas
{
    /**
     * phalanxRange
     *
     * @param int $phalanx_level Phalanx level
     *
     * return int
     */
    public static function phalanxRange(int $phalanx_level): int
    {
        $range = 0;

        if ($phalanx_level > 1) {
            $range = pow($phalanx_level, 2) - 1;
        } elseif ($phalanx_level == 1) {
            $range = 1;
        }

        return $range;
    }

    /**
     * missileRange
     *
     * @param int $impulse_drive_level Impulse drive level
     *
     * return int
     */
    public static function missileRange(int $impulse_drive_level): int
    {
        if ($impulse_drive_level > 0) {
            return ($impulse_drive_level * 5) - 1;
        }

        return 0;
    }

    /**
     * getPlanetSize
     *
     * @param int     $position Position
     * @param boolean $main     Home world
     *
     * @return array
     */
    public static function getPlanetSize(int $position, bool $main = false): array
    {
        // Per-position [min_fields, max_fields] band. The whole universe
        // sits inside [400, 600] (universe-owner requirement), but mid-system
        // positions (around 8) get a higher band than the inner/outer
        // extremes, mirroring OGame's classic feel without ever dropping
        // a colony below 400 fields. Uniform draw within each band.
        $fieldRanges = [
            1 => [400, 470],
            2 => [400, 490],
            3 => [410, 510],
            4 => [430, 540],
            5 => [450, 560],
            6 => [470, 580],
            7 => [490, 595],
            8 => [500, 600],
            9 => [490, 595],
            10 => [470, 580],
            11 => [450, 560],
            12 => [430, 540],
            13 => [410, 510],
            14 => [400, 490],
            15 => [400, 470],
        ];

        // Clamp positions outside [1, 15] so this never throws if a future
        // map generator emits an unexpected slot id. The fallback band
        // matches the inner edge (most conservative, still >= 400 fields).
        $pos = max(1, min(15, (int) $position));
        [$minFields, $maxFields] = $fieldRanges[$pos];

        $fields = mt_rand($minFields, $maxFields);

        // Diameter is now derived from the chosen field count so the
        // tooltip / admin screens stay coherent with the field grid:
        //   fields = (diameter / 1000)^2   <=>   diameter = sqrt(fields)*1000
        // PLANETSIZE_MULTIPLER is preserved as a *cosmetic* diameter
        // multiplier (some universes display physically larger worlds),
        // but field_max is taken straight from the band above, not
        // recomputed from the multiplied diameter.
        $diameter = (int) round(sqrt($fields) * 1000 * PLANETSIZE_MULTIPLER);

        if ($main) {
            $diameter = '12800';
            $fields = Functions::readConfig('initial_fields');
        }

        $return['planet_diameter'] = $diameter;
        $return['planet_field_max'] = $fields;

        return $return;
    }

    /**
     * getPlanetFields
     *
     * @param int $diameter Diameter
     *
     * @return int
     */
    public static function calculatePlanetFields(int $diameter): int
    {
        return (int) pow(($diameter / 1000), 2);
    }

    /**
     * setPlanetImage
     *
     * @param int $system   Planet system
     * @param int $position Planet position
     *
     * @return string
     */
    public static function setPlanetImage(int $system, int $position): string
    {
        // Formula based on original game values
        // How many images do we have for each planet type
        $planets_availables = [
            'dschjungel' => 10, // jungle
            'eis' => 10, // ice
            'gas' => 8, // gas
            'normaltemp' => 7, // normal
            'trocken' => 10, // dry
            'wasser' => 9, // water
            'wuesten' => 4, // desert
        ];
        $type = ['normaltemp', 'normaltemp'];

        if ($position >= 1 && $position <= 3) {
            $type = ['trocken', 'wuesten'];
        }

        if ($position >= 4 && $position <= 5) {
            $type = ['normaltemp', 'trocken'];
        }

        if ($position >= 6 && $position <= 7) {
            $type = ['dschjungel', 'normaltemp'];
        }

        if ($position >= 8 && $position <= 9) {
            $type = ['wasser', 'dschjungel'];
        }

        if ($position >= 10 && $position <= 11) {
            $type = ['eis', 'wasser'];
        }

        if ($position >= 12 && $position <= 13) {
            $type = ['gas', 'eis'];
        }

        if ($position >= 14 && $position <= 15) {
            $type = ['normaltemp', 'gas'];
        }

        // if it's an even number, we will get second element postion in the array
        if ($system % 2 == 0) {
            $even = 1;
        } else {
            $even = 0;
        }

        $image_id = mt_rand(1, $planets_availables[$type[$even]]);

        if ($image_id < 10) {
            $image_id = '0' . $image_id;
        }

        return $type[$even] . 'planet' . $image_id;
    }

    /**
     * setPlanetTemp
     *
     * @param int $position Planet position
     *
     * @return array
     */
    public static function setPlanetTemp(int $position): array
    {
        // Based on original game values
        $temp_avilable = [
            1 => [220, 260],
            2 => [170, 210],
            3 => [120, 160],
            4 => [70, 110],
            5 => [60, 100],
            6 => [50, 90],
            7 => [40, 80],
            8 => [30, 70],
            9 => [20, 60],
            10 => [10, 50],
            11 => [0, 40],
            12 => [-10, 30],
            13 => [-50, -10],
            14 => [-90, -50],
            15 => [-130, -90],
        ];

        $temperature = mt_rand($temp_avilable[$position][0], $temp_avilable[$position][1]);

        $temp['min'] = $temperature - 40;
        $temp['max'] = $temperature;

        return $temp;
    }

    /**
     * Get moon destruction chance
     *
     * @param int $planet_diameter
     * @param int $death_stars
     *
     * @return int
     */
    public static function getMoonDestructionChance(int $planet_diameter, int $death_stars): int
    {
        $prob = (100 - sqrt($planet_diameter)) * sqrt($death_stars);

        return ($prob > 100) ? 100 : round($prob);
    }

    /**
     * Get Death Stars destruction chance
     *
     * @param int $planet_diameter
     * @return float
     */
    public static function getDeathStarsDestructionChance(int $planet_diameter): float
    {
        return round(sqrt($planet_diameter) / 2);
    }

    /**
     * Get the Ion Technology Bonus
     */
    public static function getIonTechnologyBonus(int $ion_technology_level): float
    {
        return $ion_technology_level * 0.04;
    }

    /**
     * Calculate the plasma technology resource bonus
     */
    public static function getPlasmaTechnologyBonus(int $plasmaTechnologyLevel, string $resource): float
    {
        $bonus = [
            'metal' => 0.01, // 1%
            'crystal' => 0.0066, // 0.66%
            'deuterium' => 0.0033, // 0.33%
        ];

        return $plasmaTechnologyLevel * $bonus[$resource];
    }

    /**
     * Get the base cost to tear down, without influence of the ion technology
     *
     * @param int $price
     * @param float $factor
     * @param int $level
     */
    public static function getTearDownBaseCost(int $price, float $factor, int $level): int
    {
        return floor(self::getDevelopmentCost($price, $factor, ($level - 2)));
    }

    /**
     * Get the cost to tear down
     *
     * @param int $price
     * @param float $factor
     * @param int $level
     * @param int $ion_technology_level
     */
    public static function getTearDownCost(int $price, float $factor, int $level, int $ion_technology_level): int
    {
        return max(floor(self::getTearDownBaseCost($price, $factor, $level) * (1 - self::getIonTechnologyBonus($ion_technology_level))), 0);
    }

    /**
     * Get the cost to develop something
     *
     * @param int $price
     * @param float $factor
     * @param int $level
     */
    public static function getDevelopmentCost(int $price, float $factor, int $level): float
    {
        return round($price * pow($factor, $level));
    }

    /**
     * Check if the building is for destroy and calculate
     *
     * @param float $metal_cost
     * @param float $cystal_cost
     * @param int $building
     * @param int $robotics_factory
     * @param int $nanite_factory
     * @param int $level
     * @return float
     */
    public static function getTearDownTime(float $metal_cost, float $cystal_cost, int $building, int $robotics_factory, int $nanite_factory, int $level): float
    {
        $tear_down_time = self::getDevelopmentTime($metal_cost, $cystal_cost, $building, $robotics_factory, $nanite_factory, $level - 2);

        return $tear_down_time < 1 ? 1 : $tear_down_time;
    }

    /**
     * Get the time to produce ships and defenses
     *
     * @param float $metal_cost
     * @param float $cystal_cost
     * @param int $ship_defense
     * @param int $shipyard_level
     * @param int $nanite_factory_level
     * @return float
     */
    public static function getShipyardProductionTime(float $metal_cost, float $cystal_cost, int $ship_defense, int $shipyard_level, int $nanite_factory_level): float
    {
        return self::getDevelopmentTime($metal_cost, $cystal_cost, $ship_defense, $shipyard_level, $nanite_factory_level, 0, false);
    }

    /**
     * Get the time to build
     *
     * @param float $metal_cost
     * @param float $cystal_cost
     * @param int $building
     * @param int $robotics_factory
     * @param int $nanite_factory
     * @param int $level
     * @return float
     */
    public static function getBuildingTime(float $metal_cost, float $cystal_cost, int $building, int $robotics_factory, int $nanite_factory, int $level): float
    {
        return self::getDevelopmentTime($metal_cost, $cystal_cost, $building, $robotics_factory, $nanite_factory, $level);
    }

    /**
     * Get research time
     *
     * @param float $metal_cost
     * @param float $cystal_cost
     * @param int $total_lab_level
     * @param int $expedition_level
     * @return float
     */
    public static function getResearchTime(float $metal_cost, float $cystal_cost, int $total_lab_level, int $expedition_level): float
    {
        $universe_speed = (int) Functions::readConfig('game_speed') / 2500;
        $lab_boost = (1 + $total_lab_level) * pow(1.1, $total_lab_level);

        return ($metal_cost + $cystal_cost) / ($universe_speed * 1000 * $lab_boost * (1 + $expedition_level)) * 3600;
    }

    /**
     * Get the time to develop something
     *
     * @param float $metal_cost
     * @param float $cystal_cost
     * @param int $object
     * @param int $first_boost
     * @param int $second_boost
     * @param int $level
     * @param bool $reduce
     * @return float
     */
    private static function getDevelopmentTime(float $metal_cost, float $cystal_cost, int $object, int $first_boost, int $second_boost, int $level = 0, bool $reduce = true): float
    {
        $resources_needed = $metal_cost + $cystal_cost;
        $reduction = max(4 - ($level + 1) / 2, 1);
        // Keep the original linear boost and add +10% extra per level
        // for Robotics Factory (buildings) or Shipyard (ships/defenses).
        $robotics = (1 + $first_boost) * pow(1.1, $first_boost);
        $nanite = pow(2, $second_boost);
        $universe_speed = (int) Functions::readConfig('game_speed') / 2500;
        $without_reduction = [
            Buildings::BUILDING_NANO_FACTORY,
            Buildings::BUILDING_MONDBASIS,
            Buildings::BUILDING_PHALANX,
            Buildings::BUILDING_JUMP_GATE,
        ];

        if (in_array($object, $without_reduction) or $reduce == false) {
            $reduction = 1;
        }

        return $resources_needed / (2500 * $reduction * $robotics * $nanite * $universe_speed) * 3600;
    }
}
