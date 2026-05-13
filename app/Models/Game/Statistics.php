<?php

declare(strict_types=1);

namespace App\Models\Game;

use App\Core\Model;
use App\Libraries\Functions;

class Statistics extends Model
{
    /**
     * Get the amount of alliances
     *
     * @return integer
     */
    public function countAlliances(): int
    {
        return (int) $this->db->queryFetch(
            'SELECT
                COUNT(`alliance_id`) AS `count`
            FROM `' . ALLIANCE . '`;'
        )['count'];
    }

    /**
     * Get list of alliances and their statistics
     *
     * @param string $order
     * @param integer $start
     * @return array|null
     */
    public function getAlliances(string $order, int $start): ?array
    {
        return $this->db->queryFetchAll(
            'SELECT
                s.*,
                a.`alliance_id`,
                a.`alliance_tag`,
                a.`alliance_name`,
                a.`alliance_request_notallow`,
                (
                    SELECT
                        COUNT(user_id) AS `ally_members`
                    FROM `' . USERS . '`
                    WHERE `user_ally_id` = a.`alliance_id`
                ) AS `ally_members`
            FROM `' . ALLIANCE_STATISTICS . '` AS s
            INNER JOIN  `' . ALLIANCE . '` AS a ON a.`alliance_id` = s.`alliance_statistic_alliance_id`
            ORDER BY `alliance_statistic_' . $order . '` DESC, `alliance_statistic_total_rank` ASC
            LIMIT ' . $start . ',100;'
        );
    }

    /**
     * Get list of users and their statistics
     *
     * @param string $order
     * @param integer $start
     * @return array|null
     */
    public function getUsers(string $order, int $start): ?array
    {
        return $this->db->queryFetchAll(
            'SELECT
                s.*,
                u.`user_id`,
                u.`user_name`,
                u.`user_ally_id`,
                a.`alliance_name`,
                (
                    SELECT COUNT(*)
                    FROM `' . PLANETS . '` AS `p`
                    WHERE `p`.`planet_user_id` = `u`.`user_id`
                      AND `p`.`planet_type` = 1
                      AND `p`.`planet_destroyed` = 0
                      AND `p`.`planet_id` <> `u`.`user_home_planet_id`
                ) AS `user_colony_count`
            FROM `' . USERS_STATISTICS . '` as s
            INNER JOIN `' . USERS . '` as u ON u.`user_id` = s.`user_statistic_user_id`
            LEFT JOIN `' . ALLIANCE . '` AS a ON a.`alliance_id` = u.`user_ally_id`
            WHERE `user_authlevel` <= ' . Functions::readConfig('stat_admin_level') . '
            ORDER BY `user_statistic_' . $order . '` DESC, `user_statistic_total_rank` ASC
            LIMIT ' . $start . ',100;'
        );
    }

    /**
     * Player name plus all colony planets (type planet, not destroyed, not home).
     *
     * @return array{user_name:string, colonies:array<int, array<string, mixed>>}|null
     */
    public function getUserColoniesForPlayer(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $user = $this->db->queryFetch(
            'SELECT `user_id`, `user_name`, `user_home_planet_id`
             FROM `' . USERS . '`
             WHERE `user_id` = ' . $userId . '
             LIMIT 1;'
        );

        if ($user === null || empty($user['user_id'])) {
            return null;
        }

        $homeId = (int) ($user['user_home_planet_id'] ?? 0);

        $colonies = $this->db->queryFetchAll(
            'SELECT `planet_name`, `planet_galaxy`, `planet_system`, `planet_planet`
             FROM `' . PLANETS . '`
             WHERE `planet_user_id` = ' . $userId . '
               AND `planet_type` = 1
               AND `planet_destroyed` = 0
               AND `planet_id` <> ' . $homeId . '
             ORDER BY `planet_galaxy` ASC, `planet_system` ASC, `planet_planet` ASC;'
        );

        return [
            'user_name' => (string) ($user['user_name'] ?? ''),
            'colonies' => is_array($colonies) ? $colonies : [],
        ];
    }
}
