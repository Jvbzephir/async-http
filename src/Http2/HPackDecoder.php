<?php

/*
 * This file is part of KoolKode Async HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace KoolKode\Async\Http\Http2;

class HPackDecoder
{
    protected $byteOffset = 0;

    protected $bitsRemaining = 0;

    protected $current;

    protected $byte;

    protected $input;

    protected $finished = false;

    public function __construct(string $input)
    {
        $this->input = $input;
        
        if ($this->input === '') {
            $this->byte = 0xFF;
            $this->current = 0xFF;
            $this->finished = true;
        } else {
            $this->byte = \ord($input[0]);
            $this->current = $this->byte;
        }
    }
    
    public function getByte(): int
    {
        return $this->current;
    }

    public function isPaddingByte(int $byte): bool
    {
        switch ($byte) {
            case 0b1:
            case 0b11:
            case 0b111:
            case 0b1111:
            case 0b11111:
            case 0b111111:
            case 0b1111111:
                return true;
        }
        
        return false;
    }

    public function readNextByte(int $consumed)
    {
        static $masks = [
            0 => 0b00000000,
            1 => 0b00000001,
            2 => 0b00000011,
            3 => 0b00000111,
            4 => 0b00001111,
            5 => 0b00011111,
            6 => 0b00111111,
            7 => 0b01111111,
            8 => 0b11111111
        ];
        
        if ($this->finished) {
            return;
        }
        
        if ($consumed === 0) {
            return $this->current;
        }
        
        $this->current = (($this->current << $consumed) & 0xFF);
        
        if ($this->bitsRemaining >= $consumed) {
            $this->current |= (($this->byte >> ($this->bitsRemaining - $consumed)) & $masks[$consumed]);
            $this->bitsRemaining -= $consumed;
            
            return $this->current;
        }
        
        if ($this->bitsRemaining) {
            $this->current |= (($this->byte & $masks[$this->bitsRemaining]) << ($consumed - $this->bitsRemaining));
            $consumed -= $this->bitsRemaining;
        }
        
        if (isset($this->input[$this->byteOffset + 1])) {
            $this->byte = \ord($this->input[++$this->byteOffset]);
            $this->bitsRemaining = 8 - $consumed;
            $this->current |= ($this->byte >> $this->bitsRemaining);
        } elseif (!$this->finished) {
            $this->current |= $masks[$consumed];
            $this->bitsRemaining = 0;
            $this->finished = true;
            
            return $this->current;
        }
        
        return $this->current;
    }
    
    public static function regenerateDecoderTable()
    {
        $table = static::generateDecoderTable();
        
        $code = '<?php' . "\n\nreturn ";
        $code .= static::dumpTable($table);
        $code .= ";\n";
        
        \file_put_contents(\dirname(__DIR__, 2) . '/generated/hpack.decoder.php', $code);
    }

    private static function dumpTable(array $table, int $indent = 0): string
    {
        $code = "[\n";
        
        $indent++;
        $space = \str_repeat('    ', $indent);
        
        foreach ($table as $k => $data) {
            $code .= \sprintf("%s0x%02X => [\"\x%02X\", %u", $space, $k, $data[0], $data[1]);
            
            if (isset($data[2])) {
                $code .= ', ' . static::dumpTable($data[2], $indent);
            }
            
            $code .= "],\n";
        }
        
        $code = \rtrim($code, ",\n") . "\n";
        $code .= \str_repeat('    ', $indent - 1) . ']';
        
        return $code;
    }

    private static function generateDecoderTable(): array
    {
        $table = [];
        
        foreach (HPack::HUFFMAN_CODE as $i => $code) {
            $len = HPack::HUFFMAN_CODE_LENGTHS[$i];
            
            if ($len > 8) {
                $local = ($code >> ($len - 8)) & 0xFF;
            } else {
                $local = ($code << (8 - $len)) & 0xFF;
            }
            
            if (!isset($table[$local])) {
                $table[$local] = [
                    $i,
                    $len
                ];
            }
            
            if ($len > 8) {
                if (!isset($table[$local][2])) {
                    $table[$local][2] = [];
                }
                
                static::populateDecoderTable($table[$local][2], $i, $code, $len, 2);
            } else {
                for ($max = (\pow(2, 8 - $len)), $j = 0; $j < $max; $j++) {
                    $table[$local | $j] = [
                        $i,
                        $len
                    ];
                }
            }
        }
        
        \ksort($table, SORT_NUMERIC);
        
        return $table;
    }

    private static function populateDecoderTable(array & $table, int $symbol, int $code, int $len, int $level)
    {
        if ($len > 8 * $level) {
            $local = ($code >> ($len - 8 * $level)) & 0xFF;
        } else {
            $local = ($code << (8 * $level - $len)) & 0xFF;
        }
        
        if (!isset($table[$local])) {
            $table[$local] = [
                $symbol,
                $len
            ];
        }
        
        if ($len > (8 * $level)) {
            if (!isset($table[$local][2])) {
                $table[$local][2] = [];
            }
            
            static::populateDecoderTable($table[$local][2], $symbol, $code, $len, $level + 1);
        } else {
            for ($max = (\pow(2, 8 * $level - $len)), $j = 0; $j < $max; $j++) {
                $table[$local | $j] = [
                    $symbol,
                    $len
                ];
            }
        }
        
        \ksort($table, SORT_NUMERIC);
    }
}

