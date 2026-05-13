<?php

namespace App\Core\Entity;

use App\Core\Entity;

class PremiumEntity extends Entity
{
    public function __construct(array $data)
    {
        parent::__construct($data);
    }

    /**
     * Return the premium user id
     *
     * @return string
     */
    public function getPremiumUserId(): string
    {
        return $this->data['premium_user_id'];
    }

    /**
     * Return the premium dark matter
     *
     * @return string
     */
    public function getPremiumDarkMatter(): string
    {
        return $this->data['premium_dark_matter'];
    }

    /**
     * Return the premium officier commander
     *
     * @return string
     */
    public function getPremiumOfficierCommander(): string
    {
        return $this->data['premium_officier_commander'];
    }

    /**
     * Return the premium officier admiral
     *
     * @return string
     */
    public function getPremiumOfficierAdmiral(): string
    {
        return $this->data['premium_officier_admiral'];
    }

    /**
     * Return the premium officier engineer
     *
     * @return string
     */
    public function getPremiumOfficierEngineer(): string
    {
        return $this->data['premium_officier_engineer'];
    }

    /**
     * Return the premium officier geologist
     *
     * @return string
     */
    public function getPremiumOfficierGeologist(): string
    {
        return $this->data['premium_officier_geologist'];
    }

    /**
     * Return the premium officier technocrat
     *
     * @return string
     */
    public function getPremiumOfficierTechnocrat(): string
    {
        return $this->data['premium_officier_technocrat'];
    }
}
