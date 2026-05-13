<?php

namespace App\Libraries\Buddies;

use App\Core\Entity\BuddyEntity;
use App\Core\Enumerators\BuddiesStatusEnumerator as BuddiesStatus;

class Buddy
{
    private array $_buddies = [];
    private int $_current_user_id = 0;

    public function __construct(array $buddies, int $current_user_id)
    {
        if (is_array($buddies)) {
            $this->setUp($buddies);
            $this->setUserId($current_user_id);
        }
    }

    /**
     * Get all the players that received a request from this user
     *
     * @return array
     */
    public function getSentRequests(): array
    {
        $list_of_buddies = [];

        foreach ($this->_buddies as $buddy) {
            if (($buddy instanceof BuddyEntity)
                && !$this->isBuddy($buddy)
                && $this->isOwnRequest($buddy)) {
                $list_of_buddies[] = $buddy;
            }
        }

        return $list_of_buddies;
    }

    /**
     * Get all the players that sent a request to this user
     *
     * @return array
     */
    public function getReceivedRequests(): array
    {
        $list_of_buddies = [];

        foreach ($this->_buddies as $buddy) {
            if (($buddy instanceof BuddyEntity)
                && !$this->isBuddy($buddy)
                && !$this->isOwnRequest($buddy)) {
                $list_of_buddies[] = $buddy;
            }
        }

        return $list_of_buddies;
    }

    /**
     * Get all the players that are the current user's buddies
     *
     * @return array
     */
    public function getBuddies(): array
    {
        $list_of_buddies = [];

        foreach ($this->_buddies as $buddy) {
            if (($buddy instanceof BuddyEntity) && $this->isBuddy($buddy)) {
                $list_of_buddies[] = $buddy;
            }
        }

        return $list_of_buddies;
    }

    /**
     * Check if is already a buddy
     *
     * @param BuddyEntity $buddy Buddy
     *
     * @return boolean
     */
    private function isBuddy(BuddyEntity $buddy): bool
    {
        return $buddy->getBuddyStatus() == BuddiesStatus::isBuddy;
    }

    /**
     * Check if is the request owner
     *
     * @param BuddyEntity $buddy Buddy
     *
     * @return boolean
     */
    private function isOwnRequest(BuddyEntity $buddy): bool
    {
        return $buddy->getBuddySender() == $this->getUserId();
    }

    /**
     * Set up the list of buddies
     *
     * @param array $buddies Buddies
     *
     * @return void
     */
    private function setUp(array $buddies): void
    {
        foreach ($buddies as $buddy) {
            $this->_buddies[] = $this->createNewBuddyEntity($buddy);
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
     * Create a new instance of BuddyEntity
     *
     * @param array $buddy Buddy
     *
     * @return BuddyEntity
     */
    private function createNewBuddyEntity(array $buddy): BuddyEntity
    {
        return new BuddyEntity($buddy);
    }
}
