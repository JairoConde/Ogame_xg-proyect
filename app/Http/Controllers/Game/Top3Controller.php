<?php

namespace App\Http\Controllers\Game;

use App\Core\BaseController;
use App\Libraries\FormatLib;
use App\Libraries\Functions;
use App\Libraries\TimingLibrary as Timing;
use App\Libraries\Users;
use App\Models\Game\Top3;

class Top3Controller extends BaseController
{
    public const MODULE_ID = 16;

    private const CATEGORY_BUILDINGS = 'buildings';
    private const CATEGORY_SHIPS = 'ships';
    private const CATEGORY_DEFENSES = 'defenses';
    private const CATEGORY_RESEARCH = 'research';

    /**
     * User names that must never appear in the Top 3 rankings
     * (sandbox / ghost accounts used for testing or as farm dummies).
     */
    private const EXCLUDED_USER_NAMES = [
        'granjero',
    ];

    private Top3 $top3Model;

    public function __construct()
    {
        parent::__construct();

        Users::checkSession();

        parent::loadLang([
            'game/global',
            'game/top3',
            'game/constructions',
            'game/ships',
            'game/defenses',
            'game/technologies',
        ]);

        $this->top3Model = new Top3();
    }

    public function index(): void
    {
        Functions::moduleMessage(Functions::isModuleAccesible(self::MODULE_ID));

        $this->buildPage();
    }

    private function buildPage(): void
    {
        $category = $this->resolveCategory();
        $columns = $this->columnsForCategory($category);
        $rows = $this->fetchRows($category, $columns);

        $parse = $this->langs->language;
        $parse['top3_category_select'] = $this->buildCategorySelect($category);
        $parse['top3_header'] = $this->template->set('top3/top3_header', $parse);
        $parse['top3_rows'] = $this->buildRowsHtml($columns, $rows);
        $parse['stat_date'] = Timing::formatExtendedDate(Functions::readConfig('stat_last_update'));

        $this->page->display(
            $this->template->set(
                'top3/top3_body',
                $parse
            )
        );
    }

    private function resolveCategory(): string
    {
        $raw = $_POST['category'] ?? $_GET['category'] ?? self::CATEGORY_BUILDINGS;
        $raw = is_string($raw) ? strtolower($raw) : self::CATEGORY_BUILDINGS;

        $allowed = [
            self::CATEGORY_BUILDINGS,
            self::CATEGORY_SHIPS,
            self::CATEGORY_DEFENSES,
            self::CATEGORY_RESEARCH,
        ];

        return in_array($raw, $allowed, true) ? $raw : self::CATEGORY_BUILDINGS;
    }

    /**
     * Resolve the ordered list of column names for the active category,
     * using the canonical id -> column mapping from the Objects collection.
     *
     * @return string[]
     */
    private function columnsForCategory(string $category): array
    {
        $resource = $this->objects->getObjects();

        switch ($category) {
            case self::CATEGORY_SHIPS:
                $ids = $this->objects->getObjectsList('fleet') ?? [];

                break;
            case self::CATEGORY_DEFENSES:
                $ids = $this->objects->getObjectsList('defense') ?? [];

                break;
            case self::CATEGORY_RESEARCH:
                $ids = $this->objects->getObjectsList('tech') ?? [];

                break;
            case self::CATEGORY_BUILDINGS:
            default:
                $ids = $this->objects->getObjectsList('build') ?? [];

                break;
        }

        $columns = [];
        foreach ($ids as $id) {
            if (isset($resource[$id])) {
                $columns[] = $resource[$id];
            }
        }

        return $columns;
    }

    /**
     * Fetch the per-player aggregate rows for the active category.
     *
     * @param string[] $columns
     * @return array<int,array<string,mixed>>
     */
    private function fetchRows(string $category, array $columns): array
    {
        $excluded = self::EXCLUDED_USER_NAMES;

        switch ($category) {
            case self::CATEGORY_SHIPS:
                return $this->top3Model->getShipsAggregates($columns, $excluded);
            case self::CATEGORY_DEFENSES:
                return $this->top3Model->getDefensesAggregates($columns, $excluded);
            case self::CATEGORY_RESEARCH:
                return $this->top3Model->getResearchValues($columns, $excluded);
            case self::CATEGORY_BUILDINGS:
            default:
                return $this->top3Model->getBuildingsAggregates($columns, $excluded);
        }
    }

    private function buildCategorySelect(string $current): string
    {
        $options = [
            self::CATEGORY_BUILDINGS => $this->langs->line('top3_buildings'),
            self::CATEGORY_SHIPS => $this->langs->line('top3_ships'),
            self::CATEGORY_DEFENSES => $this->langs->line('top3_defenses'),
            self::CATEGORY_RESEARCH => $this->langs->line('top3_research'),
        ];

        $html = '';
        foreach ($options as $value => $label) {
            $selected = ($value === $current) ? ' SELECTED' : '';
            $html .= '<option value="' . $value . '"' . $selected . '>' . $label . '</option>';
        }

        return $html;
    }

    /**
     * Build the HTML rows for the table by computing the top 3 players
     * per item column.
     *
     * @param string[] $columns
     * @param array<int,array<string,mixed>> $rows
     */
    private function buildRowsHtml(array $columns, array $rows): string
    {
        $emptyLabel = $this->langs->line('top3_empty');
        $html = '';

        foreach ($columns as $column) {
            $top = $this->topThreeForColumn($column, $rows);

            $itemLabel = $this->langs->line($column);
            if ($itemLabel === '' || $itemLabel === $column) {
                $itemLabel = $column;
            }

            $cells = [];
            for ($i = 0; $i < 3; $i++) {
                if (!isset($top[$i])) {
                    $cells[] = $emptyLabel;

                    continue;
                }

                $name = htmlspecialchars((string) $top[$i]['user_name'], ENT_QUOTES, 'UTF-8');
                $value = FormatLib::prettyNumber((int) $top[$i]['value']);
                $cells[] = $name . ' (' . $value . ')';
            }

            $rowParse = [
                'top3_item' => htmlspecialchars($itemLabel, ENT_QUOTES, 'UTF-8'),
                'top3_first_value' => $cells[0],
                'top3_second_value' => $cells[1],
                'top3_third_value' => $cells[2],
            ];

            $html .= $this->template->set('top3/top3_row', $rowParse);
        }

        return $html;
    }

    /**
     * Sort the players by the given column desc and return the top 3 entries
     * with strictly positive values.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array{user_id:int,user_name:string,value:int}>
     */
    private function topThreeForColumn(string $column, array $rows): array
    {
        $candidates = [];
        foreach ($rows as $row) {
            if (!isset($row[$column])) {
                continue;
            }

            $value = (int) $row[$column];
            if ($value <= 0) {
                continue;
            }

            $candidates[] = [
                'user_id' => (int) $row['user_id'],
                'user_name' => (string) $row['user_name'],
                'value' => $value,
            ];
        }

        usort($candidates, function ($a, $b) {
            if ($a['value'] === $b['value']) {
                return strcmp($a['user_name'], $b['user_name']);
            }

            return $b['value'] <=> $a['value'];
        });

        return array_slice($candidates, 0, 3);
    }
}
