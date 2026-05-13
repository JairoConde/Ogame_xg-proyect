<?php

namespace App\Core\Entity;

use App\Core\Entity;

class AllianceEntity extends Entity
{
    public function __construct(array $data)
    {
        parent::__construct($data);
    }

    /**
     * Return the alliance id
     *
     * @return int
     */
    public function getAllianceId(): int
    {
        return (int) $this->data['alliance_id'];
    }

    /**
     * Return the alliance name
     *
     * @return string
     */
    public function getAllianceName(): string
    {
        return $this->data['alliance_name'];
    }

    /**
     * Return the alliance tag
     *
     * @return string
     */
    public function getAllianceTag(): string
    {
        return $this->data['alliance_tag'];
    }

    /**
     * Return the alliance owner
     *
     * @return int
     */
    public function getAllianceOwner(): int
    {
        return (int) $this->data['alliance_owner'];
    }

    /**
     * Return the alliance register time
     *
     * @return int
     */
    public function getAllianceRegisterTime(): int
    {
        return (int) $this->data['alliance_register_time'];
    }

    /**
     * Return the alliance description
     *
     * @return string|null
     */
    public function getAllianceDescription(): ?string
    {
        return $this->data['alliance_description'];
    }

    /**
     * Return the alliance web
     *
     * @return string|null
     */
    public function getAllianceWeb(): ?string
    {
        return $this->data['alliance_web'];
    }

    /**
     * Return the alliance text
     *
     * @return string|null
     */
    public function getAllianceText(): ?string
    {
        return $this->data['alliance_text'];
    }

    /**
     * Return the alliance image
     *
     * @return string|null
     */
    public function getAllianceImage(): ?string
    {
        return $this->data['alliance_image'];
    }

    /**
     * Return the alliance request
     *
     * @return string|null
     */
    public function getAllianceRequest(): ?string
    {
        return $this->data['alliance_request'];
    }

    /**
     * Return the alliance request not allow
     *
     * @return string|null
     */
    public function getAllianceRequestNotAllow(): ?string
    {
        return $this->data['alliance_request_notallow'];
    }

    /**
     * Return the alliance ranks
     *
     * @return string
     */
    public function getAllianceRanks(): string
    {
        return $this->data['alliance_ranks'];
    }

    /**
     * Return the alliance members
     *
     * @return int
     */
    public function getAllianceMembers(): int
    {
        return (int) $this->data['alliance_members'];
    }
}
