<?php

namespace App\Libraries\Research;

use App\Core\Entity\ResearchEntity;

class Researches
{
    private array $_research = [];
    private int $_current_user_id = 0;

    public function __construct(array $research, int $current_user_id)
    {
        if (is_array($research)) {
            $this->setUp($research);
            $this->setUserId($current_user_id);
        }
    }

    /**
     * Get all the research
     *
     * @return array
     */
    public function getResearch(): array
    {
        $list_of_research = [];

        foreach ($this->_research as $research) {
            if (($research instanceof ResearchEntity)) {
                $list_of_research[] = $research;
            }
        }

        return $list_of_research;
    }

    /**
     * Get current research
     *
     * @return ?ResearchEntity
     */
    public function getCurrentResearch(): ?ResearchEntity
    {
        return $this->getResearch()[0] ?? null;
    }

    /**
     * Set up the list of researches
     *
     * @param array $researches Researches
     *
     * @return void
     */
    private function setUp(array $researches): void
    {
        foreach ($researches as $research) {
            $this->_research[] = $this->createNewResearchEntity($research);
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
     * Create a new instance of ResearchEntity
     *
     * @param array $research Research
     *
     * @return ResearchEntity
     */
    private function createNewResearchEntity(array $research): ResearchEntity
    {
        return new ResearchEntity($research);
    }
}
