<?php
/*
  +---------------------------------------------------------------------------------+
  | Copyright (c) 2018 César D. Rodas                                               |
  +---------------------------------------------------------------------------------+
  | Redistribution and use in source and binary forms, with or without              |
  | modification, are permitted provided that the following conditions are met:     |
  | 1. Redistributions of source code must retain the above copyright               |
  |    notice, this list of conditions and the following disclaimer.                |
  |                                                                                 |
  | 2. Redistributions in binary form must reproduce the above copyright            |
  |    notice, this list of conditions and the following disclaimer in the          |
  |    documentation and/or other materials provided with the distribution.         |
  |                                                                                 |
  | 3. All advertising materials mentioning features or use of this software        |
  |    must display the following acknowledgement:                                  |
  |    This product includes software developed by César D. Rodas.                  |
  |                                                                                 |
  | 4. Neither the name of the César D. Rodas nor the                               |
  |    names of its contributors may be used to endorse or promote products         |
  |    derived from this software without specific prior written permission.        |
  |                                                                                 |
  | THIS SOFTWARE IS PROVIDED BY CÉSAR D. RODAS ''AS IS'' AND ANY                   |
  | EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED       |
  | WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE          |
  | DISCLAIMED. IN NO EVENT SHALL CÉSAR D. RODAS BE LIABLE FOR ANY                  |
  | DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES      |
  | (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;    |
  | LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND     |
  | ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT      |
  | (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS   |
  | SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE                     |
  +---------------------------------------------------------------------------------+
  | Authors: César Rodas <crodas@php.net>                                           |
  +---------------------------------------------------------------------------------+
*/
namespace Tuicha\Query;

use Tuicha;
use Tuicha\Fluent;
use Tuicha\Database;
use ArrayAccess;
use MongoDB\Driver\WriteConcern;

class Modify implements ArrayAccess
{
    use Fluent\Filter {
        __set as protected __set_filter;
    }

    protected $operation;
    protected $collection;
    protected $connection;
    protected $isWriting = false;

    protected $update = [];

    public function __construct($operation, $collection, Database $connection)
    {
        $this->operation  = $operation;
        $this->collection = $collection;
        $this->connection = $connection;
    }

    public function now($field, $type = 'date')
    {
        $this->update['$currentDate'][$field] = ['$type' => $type];
        return $this;
    }

    public function add($name, $value)
    {
        $this->update['$inc'][$name] = $value;
        return $this;
    }

    public function multiply($name, $value)
    {
        $this->update['$mul'][$name] = $value;
    }

    public function rename($old, $new)
    {
        $this->update['$rename'][$old] = $new;
        return $this;
    }

    public function unset()
    {
        foreach (func_get_args() as $property) {
            $this->update['$unset'][$property] = '';
        }
        return $this;
    }

    public function __set($name, $value)
    {
        if (!$this->isWriting) {
            return $this->__set_filter($name, $value);
        }

        $this->update['$set'][$name] = $value;

        return $this;
    }

    public function set($expr)
    {
        if (is_callable($expr)) {
            $this->isWriting = true;
            $expr($this);
            $this->isWriting = false;
            return $this;
        }

        $this->update = array_merge($this->update, $expr);
        return $this;
    }

    public function getUpdateDocument()
    {
        return $this->update;
    }


    public function execute($wait = null, $multi = false, $upsert = false)
    {
        if ($wait === true) {
            $wait = new WriteConcern(WriteConcern::MAJORITY);
        }

        $updates = [];
        foreach ($this->update as $operation => $values) {
            $updates[] = [
                'q' => (object)$this->filter,
                'u' => [$operation => $values],
                'upsert' => $upsert,
                'multi'  => $multi,
            ];
        }

        return Tuicha::command([
            'update' => $this->collection,
            'updates' => $updates,
            'ordered' => true,
            'writeConcern' => $wait,
        ]);
    }
}
