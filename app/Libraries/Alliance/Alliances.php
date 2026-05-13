<?php

namespace App\Libraries\Alliance;

use App\Core\Entity\AllianceEntity;
use App\Core\Enumerators\SwitchIntEnumerator;

class Alliances
{
    /**
     *
     * @var array
     */
    private array $_alliances = [];
    private int $_current_user_id = 0;
    private int $_current_user_rank_id = 0;

    public function __construct(array $alliances, int $current_user_id, int $current_user_rank_id = 0)
    {
        if (is_array($alliances)) {
            $this->setUp($alliances);
            $this->setUserId($current_user_id);
            $this->setUserRankId($current_user_rank_id);
        }
    }

    /**
     * Get all the alliances
     *
     * @return array
     */
    public function getAlliances(): array
    {
        $list_of_alliances = [];

        foreach ($this->_alliances as $alliance) {
            if (($alliance instanceof AllianceEntity)) {
                $list_of_alliances[] = $alliance;
            }
        }

        return $list_of_alliances;
    }

    /**
     * Return current alliance data
     *
     * @return AllianceEntity
     */
    public function getCurrentAlliance(): AllianceEntity
    {
        return $this->_alliances[0];
    }

    /**
     * Get current alliance rank
     *
     * @return Ranks
     */
    public function getCurrentAllianceRankObject(): Ranks
    {
        return new Ranks($this->getCurrentAlliance()->getAllianceRanks());
    }

    /**
     * Check if is the alliance owner
     *
     * @return bool
     */
    public function isOwner(): bool
    {
        return (int) $this->getCurrentAlliance()->getAllianceOwner() === $this->getUserId();
    }

    /**
     * Check the rank for the current user
     *
     * @return boolean
     */
    public function checkRank(int $rank): bool
    {
        $ranks = $this->getCurrentAllianceRankObject();

        return $rank != null
            && $ranks->getAllRanksAsArray() != null
            && $ranks->getRankById($this->getUserRankId())['rights'][$rank] == SwitchIntEnumerator::on;
    }

    /**
     * Check if the user has access to certain section of the alliance
     *
     * @param int $rank Rank
     *
     * @return boolean
     */
    public function hasAccess(int $rank): bool
    {
        return $this->isOwner() or $this->checkRank($rank);
    }

    /**
     * Set up the list of alliances
     *
     * @param array $alliances Alliances
     *
     * @return void
     */
    private function setUp(array $alliances): void
    {
        foreach ($alliances as $alliance) {
            $this->_alliances[] = $this->createNewAllianceEntity($alliance);
        }
    }

    /**
     *
     * @param int $user_id User Id
     */
    private function setUserId(int $user_id): void
    {
        $this->_current_user_id = $user_id;
    }

    /**
     *
     * @return int
     */
    private function getUserId(): int
    {
        return $this->_current_user_id;
    }

    /**
     *
     * @param int $user_rank_id User Rank Id
     */
    private function setUserRankId(int $user_rank_id): void
    {
        $this->_current_user_rank_id = $user_rank_id;
    }

    /**
     *
     * @return int
     */
    private function getUserRankId(): int
    {
        return $this->_current_user_rank_id;
    }

    /**
     * Create a new instance of AllianceEntity
     *
     * @param array $alliance Alliance
     *
     * @return AllianceEntity
     */
    private function createNewAllianceEntity(array $alliance): AllianceEntity
    {
        return new AllianceEntity($alliance);
    }
}
