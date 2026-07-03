<?php

/**
 * This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Parser;

use Symfony\Component\Yaml\Yaml;

/**
 * YAML parser. Converts data between associative array and YAML formats.
 *
 * Uses symfony/yaml. Previously bundled a vendored copy of Symfony 1.x's
 * sfYaml component that was never actually committed to this fork (a dead
 * dependency since before the PHP 8.4 port); symfony/yaml is maintained and
 * already a dependency of this project's own tooling.
 *
 * @author     Francois Zaninotto
 * @package    propel.runtime.parser
 */
class PropelYAMLParser extends PropelParser
{

	/**
	 * Converts data from an associative array to YAML.
	 *
	 * @param  array $array Source data to convert
	 * @return string Converted data, as a YAML string
	 */
	public function fromArray($array)
	{
		return Yaml::dump($array, 3, 2);
	}

	/**
	 * Alias for PropelYAMLParser::fromArray()
	 *
	 * @param  array $array Source data to convert
	 * @return string Converted data, as a YAML string
	 */
	public function toYAML($array)
	{
		return $this->fromArray($array);
	}

	/**
	 * Converts data from YAML to an associative array.
	 *
	 * @param  string $data Source data to convert, as a YAML string
	 * @return array Converted data
	 */
	public function toArray($data)
	{
		return Yaml::parse($data);
	}

	/**
	 * Alias for PropelYAMLParser::toArray()
	 *
	 * @param  string $data Source data to convert, as a YAML string
	 * @return array Converted data
	 */
	public function fromYAML($data)
	{
		return $this->toArray($data);
	}

}
