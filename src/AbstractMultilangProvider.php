<?php namespace Model\Multilang;

abstract class AbstractMultilangProvider
{
	abstract public static function dictionary(): array;
}
