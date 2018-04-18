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

use Tuicha\Fluent\Filter;
use Tuicha\Database;
use Tuicha\Metadata;
use ArrayAccess;

abstract class Modify extends Filter implements ArrayAccess
{
    protected $collection;
    protected $connection;
    protected $metadata;
    protected $options = [
        'wait' => true,
        'multi' => true,
    ];

    public function __construct($metadata, $collection, Database $connection)
    {
        $this->collection = $collection;
        $this->connection = $connection;
        $this->metadata   = $metadata;
    }

    /**
     * Toggle on or off the multi option
     *
     * The multi option tells the engine to modify at most one document if it is OFF,
     * otherwise it will modify all the documents that matches the filtering criteria.
     *
     * @param bool $multi
     *
     * @return $this
     */
    public function multi($multi = true)
    {
        $this->options['multi'] = (bool) $multi;
        return $this;
    }

    /**
     * Tell the command to wait until the operation is confirmed by the
     * database engine.
     *
     * @param bool $wait    Wether to wait or not.
     *
     * @return $this
     */
    public function wait($wait = true)
    {
        $this->options['wait'] = (bool) $wait;
        return $this;
    }

    /**
     * Returns the options for the current operation
     *
     * @return $this
     */
    public function getOptions()
    {
        return $this->options;
    }
}
