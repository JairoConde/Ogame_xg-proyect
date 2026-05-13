<?php

namespace App\Core;

use Exception;

class Entity
{
    protected array $data = [];

    public function __construct(array $data)
    {
        $this->setData($data);
    }

    /**
     * Set the current data
     *
     * @param array $data data
     *
     * @throws Exception
     *
     * @return void
     */
    private function setData(array $data): void
    {
        try {
            if (!is_array($data)) {
                return;
            }

            $this->data = $data;
        } catch (Exception $e) {
            die('Caught exception: ' . $e->getMessage() . "\n");
        }
    }
}
