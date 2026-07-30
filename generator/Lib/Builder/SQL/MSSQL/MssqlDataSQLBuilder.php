<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Generator\Builder\SQL\MSSQL;

/**
 * MS SQL Server class for building data dump SQL.
 *
 * @author     Hans Lellelid <hans@xmpl.org>
 */
use Propulsion\Generator\Builder\SQL\DataSQLBuilder;
use Propulsion\Generator\Exception\EngineException;
class MssqlDataSQLBuilder extends DataSQLBuilder
{

	/**
	 *
	 * @param      mixed $blob Blob object or string containing data.
	 * @return     string
	 */
	protected function getBlobSql($blob)
	{
		// they took magic __toString() out of PHP5.0.0; this sucks
		if (is_object($blob)) {
			if (!$blob instanceof \Stringable) {
				throw new EngineException(sprintf('BLOB value object of type %s does not implement Stringable.', get_class($blob)));
			}
			$blob = (string) $blob;
		}
		if (!is_string($blob)) {
			throw new EngineException(sprintf('BLOB value must be a string or Stringable object, got %s.', get_debug_type($blob)));
		}
		$data = unpack("H*hex", $blob);
		if (false === $data || !isset($data['hex'])) {
			throw new EngineException('Failed to unpack BLOB value into hex representation.');
		}
		return '0x'.$data['hex']; // no surrounding quotes!
	}

}
