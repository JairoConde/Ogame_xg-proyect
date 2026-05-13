<?php

namespace App\Libraries\Premium;

use App\Core\Entity\PremiumEntity;

class Premium
{
    private array $premium = [];
    private int $current_user_id = 0;

    public function __construct(array $premium, int $current_user_id)
    {
        if (is_array($premium)) {
            $this->setUp($premium);
            $this->setUserId($current_user_id);
        }
    }

    /**
     * Get all the premium
     *
     * @return array
     */
    public function getPremium(): array
    {
        $list_of_premium = [];

        foreach ($this->premium as $premium) {
            if (($premium instanceof PremiumEntity)) {
                $list_of_premium[] = $premium;
            }
        }

        return $list_of_premium;
    }

    /**
     * Get current premium
     *
     * @return ?PremiumEntity
     */
    public function getCurrentPremium(): ?PremiumEntity
    {
        return $this->getPremium()[0] ?? null;
    }

    /**
     * Set up the list of premium
     *
     * @param array $premiums Premiums
     *
     * @return void
     */
    private function setUp(array $premiums): void
    {
        foreach ($premiums as $premium) {
            $this->premium[] = $this->createNewPremiumEntity($premium);
        }
    }

    /**
     *
     * @param int $user_id User Id
     */
    private function setUserId(int $user_id): void
    {
        $this->current_user_id = $user_id;
    }

    /**
     *
     * @return int
     */
    private function getUserId(): int
    {
        return $this->current_user_id;
    }

    /**
     * Create a new instance of PremiumEntity
     *
     * @param array $premium Premium
     *
     * @return PremiumEntity
     */
    private function createNewPremiumEntity(array $premium): PremiumEntity
    {
        return new PremiumEntity($premium);
    }
}
