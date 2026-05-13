<?php

declare(strict_types=1);

namespace App\Core;

abstract class Model
{
    protected Database $db;

    public function __construct()
    {
        $this->setNewDb();
    }

    public function __destruct()
    {
        $this->db->closeConnection();
    }

    /**
     * @return array{0: \mysqli, 1: string}
     */
    public function getDbConnectionAndPrefix(): array
    {
        return [$this->db->getConnection(), $this->db->getPrefix()];
    }

    private function setNewDb(): void
    {
        $this->db = new Database();
    }
}
