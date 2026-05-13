<?php

namespace App\Core\Entity;

use App\Core\Entity;

class MessagesEntity extends Entity
{
    public function __construct(array $data)
    {
        parent::__construct($data);
    }

    /**
     * Get the message id
     *
     * @return string
     */
    public function getMessageId(): string
    {
        return $this->data['message_id'];
    }

    /**
     * Get the message sender
     *
     * @return string
     */
    public function getMessageSender(): string
    {
        return $this->data['message_sender'];
    }

    /**
     * Get the message receiver
     *
     * @return string
     */
    public function getMessageReceiver(): string
    {
        return $this->data['message_receiver'];
    }

    /**
     * Get the message time
     *
     * @return string
     */
    public function getMessageTime(): string
    {
        return $this->data['message_time'];
    }

    /**
     * Get the message type
     *
     * @return string
     */
    public function getMessageType(): string
    {
        return $this->data['message_type'];
    }

    /**
     * Get the message from
     *
     * @return string
     */
    public function getMessageFrom(): string
    {
        return $this->data['message_from'];
    }

    /**
     * Get the message subject
     *
     * @return string
     */
    public function getMessageSubject(): string
    {
        return $this->data['message_subject'];
    }

    /**
     * Get the message text
     *
     * @return string
     */
    public function getMessageText(): string
    {
        return $this->data['message_text'];
    }

    /**
     * Get the message read
     *
     * @return string
     */
    public function getMessageRead(): string
    {
        return $this->data['message_read'];
    }
}
