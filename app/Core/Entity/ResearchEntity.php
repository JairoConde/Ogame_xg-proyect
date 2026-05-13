<?php

namespace App\Core\Entity;

use App\Core\Entity;

class ResearchEntity extends Entity
{
    public function __construct(array $data)
    {
        parent::__construct($data);
    }

    /**
     * Return the research id
     *
     * @return string
     */
    public function getResearchId(): string
    {
        return $this->data['research_id'];
    }

    /**
     * Return the research user id
     *
     * @return string
     */
    public function getResearchUserId(): string
    {
        return $this->data['research_user_id'];
    }

    /**
     * Return the research current research
     *
     * @return string
     */
    public function getResearchCurrentResearch(): string
    {
        return $this->data['research_current_research'];
    }

    /**
     * Return the research espionage technology
     *
     * @return string
     */
    public function getResearchEspionageTechnology(): string
    {
        return $this->data['research_espionage_technology'];
    }

    /**
     * Return the research computer technology
     *
     * @return string
     */
    public function getResearchComputerTechnology(): string
    {
        return $this->data['research_computer_technology'];
    }

    /**
     * Return the research weapons technology
     *
     * @return string
     */
    public function getResearchWeaponsTechnology(): string
    {
        return $this->data['research_weapons_technology'];
    }

    /**
     * Return the research id
     *
     * @return string
     */
    public function getResearchShieldingTechnology(): string
    {
        return $this->data['research_shielding_technology'];
    }

    /**
     * Return the research armour technology
     *
     * @return string
     */
    public function getResearchArmourTechnology(): string
    {
        return $this->data['research_armour_technology'];
    }

    /**
     * Return the research energy technology
     *
     * @return string
     */
    public function getResearchEnergyTechnology(): string
    {
        return $this->data['research_energy_technology'];
    }

    /**
     * Return the research hyperspace technology
     *
     * @return string
     */
    public function getResearchHyperspaceTechnology(): string
    {
        return $this->data['research_hyperspace_technology'];
    }

    /**
     * Return the research combustion drive
     *
     * @return string
     */
    public function getResearchCombustionDrive(): string
    {
        return $this->data['research_combustion_drive'];
    }

    /**
     * Return the research impulse drive
     *
     * @return string
     */
    public function getResearchImpulseDrive(): string
    {
        return $this->data['research_impulse_drive'];
    }

    /**
     * Return the research hyperspace drive
     *
     * @return string
     */
    public function getResearchHyperspaceDrive(): string
    {
        return $this->data['research_hyperspace_drive'];
    }

    /**
     * Return the research laser technology
     *
     * @return string
     */
    public function getResearchLaserTechnology(): string
    {
        return $this->data['research_laser_technology'];
    }

    /**
     * Return the research ionic technology
     *
     * @return string
     */
    public function getResearchIonicTechnology(): string
    {
        return $this->data['research_ionic_technology'];
    }

    /**
     * Return the research plasma technology
     *
     * @return string
     */
    public function getResearchPlasmaTechnology(): string
    {
        return $this->data['research_plasma_technology'];
    }

    /**
     * Return the research intergalactic research network
     *
     * @return string
     */
    public function getResearchIntergalacticResearchNetwork(): string
    {
        return $this->data['research_intergalactic_research_network'];
    }

    /**
     * Return the research astrophysics
     *
     * @return string
     */
    public function getResearchAstrophysics(): string
    {
        return $this->data['research_astrophysics'];
    }

    /**
     * Return the research cargo optimization
     *
     * @return string
     */
    public function getResearchCargoOptimization(): string
    {
        return $this->data['research_cargo_optimization'];
    }

    /**
     * Return the research graviton technology
     *
     * @return string
     */
    public function getResearchGravitonTechnology(): string
    {
        return $this->data['research_graviton_technology'];
    }
}
