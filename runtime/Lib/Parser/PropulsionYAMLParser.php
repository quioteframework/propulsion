<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Parser;

use Propulsion\Exception\PropulsionException;
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
 */
class PropulsionYAMLParser extends PropulsionParser
{

	/**
	 * Converts data from an associative array to YAML.
	 *
	 * @param  array<mixed> $array Source data to convert
	 * @return string Converted data, as a YAML string
	 */
	public function fromArray($array)
	{
		return Yaml::dump($array, 3, 2);
	}

	/**
	 * Alias for PropulsionYAMLParser::fromArray()
	 *
	 * @param  array<mixed> $array Source data to convert
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
	 * @return array<mixed> Converted data
	 * @throws PropulsionException if the data does not decode to an array
	 */
	public function toArray($data)
	{
		$result = Yaml::parse($data);
		if (!is_array($result)) {
			throw new PropulsionException('Unable to decode YAML data to an array');
		}
		return $result;
	}

	/**
	 * Alias for PropulsionYAMLParser::toArray()
	 *
	 * @param  string $data Source data to convert, as a YAML string
	 * @return array<mixed> Converted data
	 */
	public function fromYAML($data)
	{
		return $this->toArray($data);
	}

}
