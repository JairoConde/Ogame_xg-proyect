<?php

declare(strict_types=1);

namespace App\Models\Game;

use App\Core\Model;
use App\Libraries\Functions;

class Top3 extends Model
{
    /**
     * Build a SELECT clause that aggregates the given columns with the
     * provided aggregate function (e.g. SUM, MAX) and aliases them
     * back to the original column name.
     *
     * @param string[] $columns
     */
    private function aggregateColumns(array $columns, string $aggregate, string $tableAlias): string
    {
        $parts = [];
        foreach ($columns as $col) {
            $safe = preg_replace('/[^a-z0-9_\-]/i', '', $col);
            $parts[] = $aggregate . '(' . $tableAlias . '.`' . $safe . '`) AS `' . $safe . '`';
        }

        return implode(",\n                ", $parts);
    }

    /**
     * Quote a list of column names safely.
     *
     * @param string[] $columns
     */
    private function quoteColumns(array $columns, string $tableAlias): string
    {
        $parts = [];
        foreach ($columns as $col) {
            $safe = preg_replace('/[^a-z0-9_\-]/i', '', $col);
            $parts[] = $tableAlias . '.`' . $safe . '`';
        }

        return implode(",\n                ", $parts);
    }

    private function adminLevel(): int
    {
        return (int) Functions::readConfig('stat_admin_level');
    }

    /**
     * Build a "AND u.user_name NOT IN (...)" clause for the given list of
     * excluded user names. Returns an empty string when no exclusions apply.
     *
     * @param string[] $excludedUserNames
     */
    private function excludedUserNamesClause(array $excludedUserNames): string
    {
        if (empty($excludedUserNames)) {
            return '';
        }

        $escaped = [];
        foreach ($excludedUserNames as $name) {
            if (!is_string($name) || $name === '') {
                continue;
            }
            $escaped[] = "'" . $this->db->escapeValue($name) . "'";
        }

        if (empty($escaped)) {
            return '';
        }

        return ' AND u.`user_name` NOT IN (' . implode(', ', $escaped) . ')';
    }

    /**
     * Get the per-player aggregated values for every requested building column.
     * Buildings live on planets; we report the MAX level across the player's
     * planets so the metric matches "best planet level".
     *
     * @param string[] $columns
     * @param string[] $excludedUserNames User names that must be excluded from the result.
     * @return array<int,array<string,mixed>> Each row: user_id, user_name + one column per requested building.
     */
    public function getBuildingsAggregates(array $columns, array $excludedUserNames = []): array
    {
        if (empty($columns)) {
            return [];
        }

        $select = $this->aggregateColumns($columns, 'MAX', 'b');

        $sql = 'SELECT
                u.`user_id`,
                u.`user_name`,
                ' . $select . '
            FROM `' . BUILDINGS . '` AS b
            INNER JOIN `' . PLANETS . '` AS p ON p.`planet_id` = b.`building_planet_id`
            INNER JOIN `' . USERS . '` AS u ON u.`user_id` = p.`planet_user_id`
            WHERE u.`user_authlevel` <= ' . $this->adminLevel() . $this->excludedUserNamesClause($excludedUserNames) . '
            GROUP BY u.`user_id`, u.`user_name`;';

        $rows = $this->db->queryFetchAll($sql);

        return $rows ?? [];
    }

    /**
     * Get the per-player aggregated values for every requested ship column.
     * Ships live on planets; we sum across all the player's planets.
     *
     * @param string[] $columns
     * @param string[] $excludedUserNames User names that must be excluded from the result.
     * @return array<int,array<string,mixed>>
     */
    public function getShipsAggregates(array $columns, array $excludedUserNames = []): array
    {
        if (empty($columns)) {
            return [];
        }

        $select = $this->aggregateColumns($columns, 'SUM', 's');

        $sql = 'SELECT
                u.`user_id`,
                u.`user_name`,
                ' . $select . '
            FROM `' . SHIPS . '` AS s
            INNER JOIN `' . PLANETS . '` AS p ON p.`planet_id` = s.`ship_planet_id`
            INNER JOIN `' . USERS . '` AS u ON u.`user_id` = p.`planet_user_id`
            WHERE u.`user_authlevel` <= ' . $this->adminLevel() . $this->excludedUserNamesClause($excludedUserNames) . '
            GROUP BY u.`user_id`, u.`user_name`;';

        $rows = $this->db->queryFetchAll($sql);

        return $rows ?? [];
    }

    /**
     * Get the per-player aggregated values for every requested defense column.
     * Defenses live on planets; we sum across all the player's planets.
     *
     * @param string[] $columns
     * @param string[] $excludedUserNames User names that must be excluded from the result.
     * @return array<int,array<string,mixed>>
     */
    public function getDefensesAggregates(array $columns, array $excludedUserNames = []): array
    {
        if (empty($columns)) {
            return [];
        }

        $select = $this->aggregateColumns($columns, 'SUM', 'd');

        $sql = 'SELECT
                u.`user_id`,
                u.`user_name`,
                ' . $select . '
            FROM `' . DEFENSES . '` AS d
            INNER JOIN `' . PLANETS . '` AS p ON p.`planet_id` = d.`defense_planet_id`
            INNER JOIN `' . USERS . '` AS u ON u.`user_id` = p.`planet_user_id`
            WHERE u.`user_authlevel` <= ' . $this->adminLevel() . $this->excludedUserNamesClause($excludedUserNames) . '
            GROUP BY u.`user_id`, u.`user_name`;';

        $rows = $this->db->queryFetchAll($sql);

        return $rows ?? [];
    }

    /**
     * Get the per-player values for every requested research column.
     * Research is one row per user, no aggregation needed.
     *
     * @param string[] $columns
     * @param string[] $excludedUserNames User names that must be excluded from the result.
     * @return array<int,array<string,mixed>>
     */
    public function getResearchValues(array $columns, array $excludedUserNames = []): array
    {
        if (empty($columns)) {
            return [];
        }

        $select = $this->quoteColumns($columns, 'r');

        $sql = 'SELECT
                u.`user_id`,
                u.`user_name`,
                ' . $select . '
            FROM `' . RESEARCH . '` AS r
            INNER JOIN `' . USERS . '` AS u ON u.`user_id` = r.`research_user_id`
            WHERE u.`user_authlevel` <= ' . $this->adminLevel() . $this->excludedUserNamesClause($excludedUserNames) . ';';

        $rows = $this->db->queryFetchAll($sql);

        return $rows ?? [];
    }
}
