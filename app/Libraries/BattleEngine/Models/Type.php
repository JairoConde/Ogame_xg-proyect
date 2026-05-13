<?php

namespace App\Libraries\BattleEngine\Models;

/**
 *  OPBE
 *  Copyright (C) 2013  Jstar
 *
 * This file is part of OPBE.
 *
 * OPBE is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * OPBE is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with OPBE.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @package OPBE
 * @author Jstar <frascafresca@gmail.com>
 * @copyright 2013 Jstar <frascafresca@gmail.com>
 * @license http://www.gnu.org/licenses/ GNU AGPLv3 License
 * @version beta(26-10-2013)
 * @link https://github.com/jstar88/opbe
 */
class Type
{
    private int $id;
    private int $count;

    public function __construct(int $id, int $count)
    {
        $this->id = $id;
        $this->count = $count;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getCount(): int
    {
        return $this->count;
    }

    public function increment(int $number): void
    {
        $this->count += $number;
    }

    public function decrement(int $number): void
    {
        $this->count -= $number;
    }

    public function setCount(int $number): void
    {
        $this->count = $number;
    }

    public function __toString(): string
    {
        ob_start();
        $_type = $this;
        require OPBEPATH . 'Views/type.html';

        return ob_get_clean();
    }

    public function isEmpty(): bool
    {
        return $this->count == 0;
    }

    public function cloneMe(): static
    {
        return new Type($this->id, $this->count);
    }
}
