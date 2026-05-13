<?php

namespace App\Models\Game;

use App\Core\Model;

class Trader extends Model
{
    public function refillStorage(int $dark_matter, string $resource, float $amount, int $user_id, int $planet_id): void
    {
        $this->db->query(
            'UPDATE `' . PREMIUM . '` pr, `' . PLANETS . "` p SET
            pr.`premium_dark_matter` = pr.`premium_dark_matter` - '" . $dark_matter . "',
            p.`planet_" . $resource . "` = '" . $amount . "'
            WHERE pr.`premium_user_id` = '" . $user_id . "'
                AND p.`planet_id` = '" . $planet_id . "';"
        );
    }

    public function tradeResources(
        int $user_id,
        int $planet_id,
        string $sell_resource,
        int $sell_amount,
        array $exchange,
        int $dark_matter_price
    ): bool {
        $allowed = ['metal', 'crystal', 'deuterium'];
        if (!in_array($sell_resource, $allowed, true)) {
            return false;
        }

        $updates = [];
        foreach ($allowed as $resource) {
            if ($resource === $sell_resource) {
                $updates[] = "p.`planet_{$resource}` = p.`planet_{$resource}` - " . $sell_amount;

                continue;
            }

            $gain = max(0, (int) ($exchange[$resource] ?? 0));
            $updates[] = "p.`planet_{$resource}` = p.`planet_{$resource}` + " . $gain;
        }

        $query = 'UPDATE `' . PREMIUM . '` pr, `' . PLANETS . '` p SET
            pr.`premium_dark_matter` = pr.`premium_dark_matter` - ' . $dark_matter_price . ',
            ' . implode(",\n            ", $updates) . "
            WHERE pr.`premium_user_id` = '" . $user_id . "'
              AND p.`planet_id` = '" . $planet_id . "'
              AND p.`planet_user_id` = '" . $user_id . "'
              AND p.`planet_{$sell_resource}` >= " . $sell_amount . '
              AND pr.`premium_dark_matter` >= ' . $dark_matter_price . ';';

        $this->db->query($query);

        return $this->db->affectedRows() > 0;
    }
}
