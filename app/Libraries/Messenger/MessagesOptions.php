<?php

namespace App\Libraries\Messenger;

use App\Core\Enumerators\MessagesEnumerator;
use App\Helpers\StringsHelper;

final class MessagesOptions
{
    private mixed $_to = null;
    private mixed $_sender = null;
    private mixed $_time = null;
    private mixed $_type = null;
    private mixed $_from = null;
    private mixed $_subject = null;
    private mixed $_message_text = null;
    private mixed $_message_format = null;

    /**
     * @return mixed
     */
    public function getTo(): mixed
    {
        return $this->_to;
    }

    /**
     * @return mixed
     */
    public function getSender(): mixed
    {
        return $this->_sender == '' ? 0 : $this->_sender;
    }

    /**
     * @return mixed
     */
    public function getTime(): mixed
    {
        return $this->_time == '' ? time() : $this->_time;
    }

    /**
     * @return mixed
     */
    public function getType(): mixed
    {
        if ($this->_type == '' or !is_object($this->_type)) {
            return MessagesEnumerator::GENERAL;
        }

        return $this->_type;
    }

    /**
     * @return mixed
     */
    public function getFrom(): mixed
    {
        return $this->_from;
    }

    /**
     * @return mixed
     */
    public function getSubject(): mixed
    {
        return $this->_subject;
    }

    /**
     * @return mixed
     */
    public function getMessageText(): mixed
    {
        return $this->_message_text;
    }

    /**
     * @return mixed
     */
    public function getMessageFormat(): mixed
    {
        if ($this->_message_format == '') {
            return 1;
        }

        return $this->_message_format;
    }

    /**
     * @param $to
     */
    public function setTo(mixed $to): void
    {
        $this->_to = $to;
    }

    /**
     * @param $sender
     */
    public function setSender(mixed $sender): void
    {
        $this->_sender = $sender;
    }

    /**
     * @param $time
     */
    public function setTime(mixed $time): void
    {
        $this->_time = $time;
    }

    /**
     * @param $type
     */
    public function setType(mixed $type): void
    {
        $this->_type = $type;
    }

    /**
     * @param $from
     */
    public function setFrom(mixed $from): void
    {
        $this->_from = $from;
    }

    /**
     * @param $subject
     */
    public function setSubject(mixed $subject): void
    {
        $this->_subject = $subject;
    }

    /**
     * @param $message_text
     */
    public function setMessageText(mixed $message_text): void
    {
        if ($this->_message_format == 1) {
            $this->_message_text = stripslashes($message_text);
        } else {
            $this->_message_text = StringsHelper::escapeString($message_text);
        }
    }

    /**
     * @param $message_format
     */
    public function setMessageFormat(mixed $message_format): void
    {
        $this->_message_format = $message_format;
    }
}
