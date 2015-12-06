<?php

namespace KoolKode\Async\Http;

class HttpData
{
    public $data;
    
    public function __construct(string $data)
    {
        $this->data = $data;
    }
    
    public function __toString()
    {
        return $this->data;
    }
}
