<?php

namespace App\Core\Entity;

use App\Core\Entity;

class NotesEntity extends Entity
{
    public function __construct(array $data)
    {
        parent::__construct($data);
    }

    /**
     * Return the note id
     *
     * @return string
     */
    public function getNoteId(): string
    {
        return $this->data['note_id'];
    }

    /**
     * Return the note owner
     *
     * @return string
     */
    public function getNoteOwner(): string
    {
        return $this->data['note_owner'];
    }

    /**
     * Return the note time
     *
     * @return string
     */
    public function getNoteTime(): string
    {
        return $this->data['note_time'];
    }

    /**
     * Return the note priority
     *
     * @return string
     */
    public function getNotePriority(): string
    {
        return $this->data['note_priority'];
    }

    /**
     * Return the note title
     *
     * @return string
     */
    public function getNoteTitle(): string
    {
        return $this->data['note_title'];
    }

    /**
     * Return the note text
     *
     * @return string
     */
    public function getNoteText(): string
    {
        return $this->data['note_text'];
    }
}
