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

/**
 * JSON parser. Converts data between associative array and JSON formats
 *
 * @author     Francois Zaninotto
 */
class PropulsionJSONParser extends PropulsionParser
{

	/**
	 * Converts data from an associative array to JSON.
	 *
	 * @param  array<mixed> $array Source data to convert
	 * @return string Converted data, as a JSON string
	 * @throws PropulsionException if the data cannot be encoded to JSON
	 */
	public function fromArray($array)
	{
		$json = json_encode($array);
		if ($json === false) {
			throw new PropulsionException('Unable to encode data as JSON: ' . json_last_error_msg());
		}
		return $json;
	}

	/**
	 * Alias for PropulsionJSONParser::fromArray()
	 *
	 * @param  array<mixed> $array Source data to convert
	 * @return string Converted data, as a JSON string
	 */
	public function toJSON($array)
	{
		return $this->fromArray($array);
	}

	/**
	 * Converts data from JSON to an associative array.
	 *
	 * @param  string $data Source data to convert, as a JSON string
	 * @return array<mixed> Converted data
	 * @throws PropulsionException if the data cannot be decoded to an array
	 */
	public function toArray($data)
	{
		$result = json_decode($data, true);
		if (!is_array($result)) {
			throw new PropulsionException('Unable to decode JSON data to an array: ' . json_last_error_msg());
		}
		return $result;
	}

	/**
	 * Alias for PropulsionJSONParser::toArray()
	 *
	 * @param  string $data Source data to convert, as a JSON string
	 * @return array<mixed> Converted data
	 */
	public function fromJSON($data)
	{
		return $this->toArray($data);
	}

}
