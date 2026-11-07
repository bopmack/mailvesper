<?php

namespace App\Services\Journeys;

use InvalidArgumentException;

/**
 * The AI agent's Calculator tool: arithmetic only (numbers, + - * / % ^, brackets), parsed by hand, never eval'd.
 */
class Calculator
{
    private string $s = '';

    private int $i = 0;

    public static function evaluate(string $expression): float|int
    {
        $c = new self;
        $c->s = str_replace([' ', "\t", ','], '', mb_substr($expression, 0, 300));
        if ($c->s === '' || ! preg_match('/^[0-9.+\-*\/%^()]+$/', $c->s)) {
            throw new InvalidArgumentException('Use numbers and + - * / % ^ ( ) only.');
        }
        $v = $c->sum();
        if ($c->i < strlen($c->s)) {
            throw new InvalidArgumentException('Couldn’t read the expression after “'.substr($c->s, 0, $c->i).'”.');
        }
        if (! is_finite($v)) {
            throw new InvalidArgumentException('The result isn’t a finite number.');
        }

        return floor($v) === $v && abs($v) < PHP_INT_MAX ? (int) $v : round($v, 10);
    }

    private function sum(): float
    {
        $v = $this->product();
        while ($this->i < strlen($this->s) && in_array($this->s[$this->i], ['+', '-'], true)) {
            $op = $this->s[$this->i++];
            $r = $this->product();
            $v = $op === '+' ? $v + $r : $v - $r;
        }

        return $v;
    }

    private function product(): float
    {
        $v = $this->power();
        while ($this->i < strlen($this->s) && in_array($this->s[$this->i], ['*', '/', '%'], true)) {
            $op = $this->s[$this->i++];
            $r = $this->power();
            if ($op !== '*' && $r == 0.0) {
                throw new InvalidArgumentException('Division by zero.');
            }
            $v = match ($op) {
                '*' => $v * $r,
                '/' => $v / $r,
                default => fmod($v, $r),
            };
        }

        return $v;
    }

    private function power(): float
    {
        $v = $this->unary();
        if ($this->i < strlen($this->s) && $this->s[$this->i] === '^') {
            $this->i++;
            $e = $this->power(); // right-associative
            if (abs($e) > 64) {
                throw new InvalidArgumentException('Powers above 64 aren’t supported.');
            }
            $v **= $e;
        }

        return $v;
    }

    private function unary(): float
    {
        if ($this->i < strlen($this->s) && in_array($this->s[$this->i], ['+', '-'], true)) {
            return $this->s[$this->i++] === '-' ? -$this->unary() : $this->unary();
        }
        if ($this->i < strlen($this->s) && $this->s[$this->i] === '(') {
            $this->i++;
            $v = $this->sum();
            if (($this->s[$this->i] ?? '') !== ')') {
                throw new InvalidArgumentException('A bracket isn’t closed.');
            }
            $this->i++;

            return $v;
        }
        if (! preg_match('/\G\d+(\.\d+)?|\G\.\d+/', $this->s, $m, 0, $this->i)) {
            throw new InvalidArgumentException('Expected a number at “'.substr($this->s, $this->i, 10).'”.');
        }
        $this->i += strlen($m[0]);

        return (float) $m[0];
    }
}
