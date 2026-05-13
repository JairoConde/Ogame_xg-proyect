<?php

namespace App\Http\Controllers\Game;

use App\Core\BaseController;
use App\Libraries\FormatLib as Format;
use App\Libraries\Functions;
use App\Libraries\Users;
use App\Models\Game\Trader;

class TraderLayerController extends BaseController
{
    public const MODULE_ID = 5;
    private const MERCHANT_CALL_PRICE = 3500;
    private const SELL_RATIOS = [
        'metal' => ['crystal' => 2.0, 'deuterium' => 4.0],
        'crystal' => ['metal' => 0.5, 'deuterium' => 2.0],
        'deuterium' => ['metal' => 0.25, 'crystal' => 0.5],
    ];

    private string $error = '';
    private Trader $traderModel;

    public function __construct()
    {
        parent::__construct();

        // check if session is active
        Users::checkSession();

        // load Language
        parent::loadLang(['game/global', 'game/trader']);
        $this->traderModel = new Trader();
    }

    public function index(): void
    {
        // Check module access
        Functions::moduleMessage(Functions::isModuleAccesible(self::MODULE_ID));

        $this->runAction();

        // build the page
        $this->buildPage();
    }

    private function runAction(): void
    {
        $trade = filter_input_array(INPUT_POST);

        if (!$trade || !isset($trade['execute_trade'])) {
            return;
        }

        $sellResource = filter_input(INPUT_POST, 'sell', FILTER_UNSAFE_RAW);
        if (!is_string($sellResource) || !isset(self::SELL_RATIOS[$sellResource])) {
            $this->error = $this->langs->line('tr_invalid_resource_selection');

            return;
        }

        $exchange = [];
        foreach (['metal', 'crystal', 'deuterium'] as $resource) {
            if ($resource === $sellResource) {
                continue;
            }

            $value = filter_input(INPUT_POST, $resource, FILTER_VALIDATE_INT, ['options' => ['default' => 0, 'min_range' => 0]]);
            $exchange[$resource] = max(0, (int) $value);
        }

        if (array_sum($exchange) <= 0) {
            $this->error = $this->langs->line('tr_invalid_trade_amount');

            return;
        }

        $soldAmount = 0;
        foreach ($exchange as $resource => $amount) {
            $soldAmount += (int) ceil($amount * self::SELL_RATIOS[$sellResource][$resource]);
            $newAmount = (int) $this->planet['planet_' . $resource] + $amount;
            if ($newAmount > (int) $this->planet['planet_' . $resource . '_max']) {
                $this->error = $this->langs->line('tr_no_enough_storage');

                return;
            }
        }

        if ((int) $this->planet['planet_' . $sellResource] < $soldAmount) {
            $this->error = $this->langs->line('tr_not_enough_sell_resource');

            return;
        }

        if ((int) $this->user['premium_dark_matter'] < self::MERCHANT_CALL_PRICE) {
            $this->error = $this->langs->line('tr_no_enough_dark_matter');

            return;
        }

        $planetId = (int) $this->planet['planet_id'];
        $userId = (int) $this->user['user_id'];
        $ok = $this->traderModel->tradeResources(
            $userId,
            $planetId,
            $sellResource,
            $soldAmount,
            $exchange,
            self::MERCHANT_CALL_PRICE
        );

        if (!$ok) {
            $this->error = $this->langs->line('tr_trade_failed');

            return;
        }

        Functions::redirect('game.php?page=traderLayer&mode=traderResources&sell=' . $sellResource . '&ok=1');
    }

    private function buildPage(): void
    {
        $resourceData = $this->buildResourcesSection();

        $this->page->display(
            $this->template->set(
                'game/trader_layer_view',
                array_merge(
                    $this->langs->language,
                    $resourceData,
                    [
                        'dpath' => DPATH,
                        'status_message' => $this->buildStatusMessage(),
                    ]
                )
            ),
            '',
            false,
            false
        );
    }
    /*
    $parse = $this->langs;

    if ($this->current_user['premium_dark_matter'] < $this->tr_dark_matter) {

    Functions::message(
    str_replace(
    '%s', $this->tr_dark_matter, $this->langs['tr_darkmatter_needed']
    ), '', '', true
    );

    die();
    }

    if (isset($_POST['ress']) && $_POST['ress'] != '') {

    switch ($_POST['ress']) {

    case 'metal':
    if ($_POST['cristal'] < 0 or $_POST['deut'] < 0) {
    Functions::message($this->langs['tr_only_positive_numbers'], "game.php?page=traderOverview", 1);
    } else {
    $necessaire = (($_POST['cristal'] * 2) + ($_POST['deut'] * 4));
    $amout = array(
    'metal' => 0,
    'crystal' => $_POST['cristal'],
    'deuterium' => $_POST['deut'],
    );

    $storage = $this->checkStorage($amout);

    if (is_string($storage)) {

    die(Functions::message($storage, 'game.php?page=traderOverview', '2'));
    }

    if ($this->current_planet['planet_metal'] > $necessaire) {

    $this->db->query(
    "UPDATE " . PLANETS . " SET
    `planet_metal` = `planet_metal` - " . round($necessaire) . ",
    `planet_crystal` = `planet_crystal` + " . round($_POST['cristal']) . ",
    `planet_deuterium` = `planet_deuterium` + " . round($_POST['deut']) . "
    WHERE `planet_id` = '" . $this->current_planet['planet_id'] . "';"
    );

    $this->current_planet['planet_metal'] -= $necessaire;
    $this->current_planet['planet_crystal'] += isset($_POST['cristal']) ? $_POST['cristal'] : 0;
    $this->current_planet['planet_deuterium'] += isset($_POST['deut']) ? $_POST['deut'] : 0;

    $this->discountDarkMatter(); // REDUCE DARKMATTER
    } else {

    Functions::message($this->langs['tr_not_enought_metal'], "game.php?page=traderOverview", 1);
    }
    }
    break;

    case 'cristal':
    if ($_POST['metal'] < 0 or $_POST['deut'] < 0) {

    Functions::message($this->langs['tr_only_positive_numbers'], "game.php?page=traderOverview", 1);
    } else {

    $necessaire = ((abs($_POST['metal']) * 0.5) + (abs($_POST['deut']) * 2));
    $amout = array(
    'metal' => $_POST['metal'],
    'crystal' => 0,
    'deuterium' => $_POST['deut'],
    );

    $storage = $this->checkStorage($amout);

    if (is_string($storage)) {

    die(Functions::message($storage, 'game.php?page=traderOverview', '2'));
    }

    if ($this->current_planet['planet_crystal'] > $necessaire) {

    $this->db->query(
    "UPDATE " . PLANETS . " SET
    `planet_metal` = `planet_metal` + " . round($_POST['metal']) . ",
    `planet_crystal` = `planet_crystal` - " . round($necessaire) . ",
    `planet_deuterium` = `planet_deuterium` + " . round($_POST['deut']) . "
    WHERE `planet_id` = '" . $this->current_planet['planet_id'] . "';"
    );

    $this->current_planet['planet_metal'] += isset($_POST['metal']) ? $_POST['metal'] : 0;
    $this->current_planet['planet_crystal'] -= $necessaire;
    $this->current_planet['planet_deuterium'] += isset($_POST['deut']) ? $_POST['deut'] : 0;

    $this->discountDarkMatter(); // REDUCE DARKMATTER
    } else {

    Functions::message($this->langs['tr_not_enought_crystal'], "game.php?page=traderOverview", 1);
    }
    }
    break;

    case 'deuterium':
    if ($_POST['cristal'] < 0 or $_POST['metal'] < 0) {

    Functions::message($this->langs['tr_only_positive_numbers'], "game.php?page=traderOverview", 1);
    } else {

    $necessaire = ((abs($_POST['metal']) * 0.25) + (abs($_POST['cristal']) * 0.5));
    $amout = array(
    'metal' => $_POST['metal'],
    'crystal' => $_POST['cristal'],
    'deuterium' => 0,
    );

    $storage = $this->checkStorage($amout);

    if (is_string($storage)) {

    die(Functions::message($storage, 'game.php?page=traderOverview', '2'));
    }

    if ($this->current_planet['planet_deuterium'] > $necessaire) {

    $this->db->query(
    "UPDATE " . PLANETS . " SET
    `planet_metal` = `planet_metal` + " . round($_POST['metal']) . ",
    `planet_crystal` = `planet_crystal` + " . round($_POST['cristal']) . ",
    `planet_deuterium` = `planet_deuterium` - " . round($necessaire) . "
    WHERE `planet_id` = '" . $this->current_planet['planet_id'] . "';"
    );

    $this->current_planet['planet_metal'] += isset($_POST['metal']) ? $_POST['metal'] : 0;
    $this->current_planet['planet_crystal'] += isset($_POST['cristal']) ? $_POST['cristal'] : 0;
    $this->current_planet['planet_deuterium'] -= $necessaire;

    $this->discountDarkMatter(); // REDUCE DARKMATTER
    } else {

    Functions::message($this->langs['tr_not_enought_deuterium'], "game.php?page=traderOverview", 1);
    }
    }
    break;
    }

    Functions::message($this->langs['tr_exchange_done'], "game.php?page=traderOverview", 1);
    } else {

    $template = 'trader/trader_main';

    if (isset($_POST['action'])) {

    $parse['mod_ma_res'] = '1';

    switch ((isset($_POST['choix']) ? $_POST['choix'] : null)) {

    case 'metal':
    $template = 'trader/trader_metal';

    $parse['mod_ma_res_a'] = '2';
    $parse['mod_ma_res_b'] = '4';

    break;

    case 'cristal':
    $template = 'trader/trader_cristal';

    $parse['mod_ma_res_a'] = '0.5';
    $parse['mod_ma_res_b'] = '2';

    break;

    case 'deut':
    $template = 'trader/trader_deuterium';

    $parse['mod_ma_res_a'] = '0.25';
    $parse['mod_ma_res_b'] = '0.5';

    break;
    }
    }
    }

    $this->page->display($this->template->set($template, $parse));*/
    //}

    /**
     * Build resources section
     *
     * @return array
     */
    private function buildResourcesSection(): array
    {
        $sellResource = filter_input(INPUT_POST, 'sell', FILTER_UNSAFE_RAW);
        if (!is_string($sellResource) || !isset(self::SELL_RATIOS[$sellResource])) {
            $sellResource = filter_input(INPUT_GET, 'sell', FILTER_UNSAFE_RAW);
        }
        if (!is_string($sellResource) || !isset(self::SELL_RATIOS[$sellResource])) {
            $sellResource = 'metal';
        }

        $buyResources = array_values(array_filter(['metal', 'crystal', 'deuterium'], static fn (string $resource): bool => $resource !== $sellResource));

        return array_merge(
            $this->langs->language,
            [
                'sell_resource' => $sellResource,
                'sell_resource_name' => $this->langs->line($sellResource),
                'sell_available' => Format::prettyNumber((int) $this->planet['planet_' . $sellResource]),
                'call_price' => Format::prettyNumber(self::MERCHANT_CALL_PRICE),
                'resource_a' => $buyResources[0],
                'resource_b' => $buyResources[1],
                'resource_a_name' => $this->langs->line($buyResources[0]),
                'resource_b_name' => $this->langs->line($buyResources[1]),
                'resource_a_current' => Format::prettyNumber((int) $this->planet['planet_' . $buyResources[0]]),
                'resource_b_current' => Format::prettyNumber((int) $this->planet['planet_' . $buyResources[1]]),
                'resource_a_free' => Format::prettyNumber(max(0, (int) $this->planet['planet_' . $buyResources[0] . '_max'] - (int) $this->planet['planet_' . $buyResources[0]])),
                'resource_b_free' => Format::prettyNumber(max(0, (int) $this->planet['planet_' . $buyResources[1] . '_max'] - (int) $this->planet['planet_' . $buyResources[1]])),
                'ratio_a' => self::SELL_RATIOS[$sellResource][$buyResources[0]],
                'ratio_b' => self::SELL_RATIOS[$sellResource][$buyResources[1]],
            ]
        );
    }

    private function buildStatusMessage(): string
    {
        if ($this->error !== '') {
            return '<div class="error">' . $this->error . '</div>';
        }

        if (filter_input(INPUT_GET, 'ok', FILTER_VALIDATE_INT) === 1) {
            return '<div class="success">' . $this->langs->line('tr_exchange_done') . '</div>';
        }

        return '';
    }
}
