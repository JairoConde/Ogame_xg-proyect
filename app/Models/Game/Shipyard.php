<?php

namespace App\Models\Game;

use App\Core\Model;

class Shipyard extends Model
{
    /**
     * Update the planets table, set the items to build and reduce the resources
     *
     * @param array $resources
     * @param string $shipyard_queue
     * @param int $planet_id
     *
     * @return void
     */
    public function insertItemsToBuild($resources, $shipyard_queue, $planet_id)
    {
        $this->db->query(
            'UPDATE ' . PLANETS . " AS p SET
                p.`planet_b_hangar_id` = CONCAT(p.`planet_b_hangar_id`, '" . $shipyard_queue . "'),
                p.`planet_metal` = '" . $resources['metal'] . "',
                p.`planet_crystal` = '" . $resources['crystal'] . "',
                p.`planet_deuterium` = '" . $resources['deuterium'] . "'
            WHERE p.`planet_id` = '" . $planet_id . "';"
        );
    }

    public function cancelQueueAndRefund(array $resources, int $planet_id): void
    {
        $this->db->query(
            'UPDATE ' . PLANETS . " AS p SET
                p.`planet_b_hangar_id` = '',
                p.`planet_b_hangar` = '0',
                p.`planet_metal` = p.`planet_metal` + '" . $resources['metal'] . "',
                p.`planet_crystal` = p.`planet_crystal` + '" . $resources['crystal'] . "',
                p.`planet_deuterium` = p.`planet_deuterium` + '" . $resources['deuterium'] . "'
            WHERE p.`planet_id` = '" . $planet_id . "';"
        );
    }
}
