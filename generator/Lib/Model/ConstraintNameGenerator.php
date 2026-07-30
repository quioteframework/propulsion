<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
 namespace Propulsion\Generator\Model;

/**
 * A <code>NameGenerator</code> implementation for table-specific
 * constraints.  Conforms to the maximum column name length for the
 * type of database in use.
 *
 * @author     Hans Lellelid <hans@xmpl.org> (Propel)
 * @author     Daniel Rall <dlr@finemaltcoding.com> (Torque)
 * @version    $Revision$
 */
use Propulsion\Generator\Exception\EngineException;

class ConstraintNameGenerator implements NameGenerator
{
	/**
	 * Conditional compilation flag.
	 */
	const DEBUG = false;

	/**
	 * First element of <code>inputs</code> should be of type {@link Database}, second
	 * should be a table name, third is the type identifier (spared if
	 * trimming is necessary due to database type length constraints),
	 * and the fourth is a <code>Integer</code> indicating the number
	 * of this contraint.
	 *
	 * @param      array<int, mixed> $inputs
	 * @see        NameGenerator
	 * @throws     EngineException
	 */
	public function generateName($inputs)
	{

		$db = $inputs[0] ?? null;
		if (!($db instanceof Database)) {
			throw new EngineException('ConstraintNameGenerator::generateName() expects a Database as $inputs[0].');
		}
		$name = $inputs[1] ?? null;
		if (!is_string($name)) {
			throw new EngineException('ConstraintNameGenerator::generateName() expects a string name as $inputs[1].');
		}
		$namePostfix = $inputs[2] ?? null;
		if (!is_string($namePostfix)) {
			throw new EngineException('ConstraintNameGenerator::generateName() expects a string postfix as $inputs[2].');
		}
		$constraintNbrRaw = $inputs[3] ?? null;
		if (!is_int($constraintNbrRaw) && !is_string($constraintNbrRaw)) {
			throw new EngineException('ConstraintNameGenerator::generateName() expects an int/string constraint number as $inputs[3].');
		}
		$constraintNbr = (string) $constraintNbrRaw;

		// Calculate maximum RDBMS-specific column character limit.
		$maxBodyLength = -1;
		try {
			$platform = $db->getPlatform();
			if ($platform === null) {
				throw new EngineException('Cannot generate a constraint name: no platform is configured for this Database.');
			}
			$maxColumnNameLength = $platform->getMaxColumnNameLength();
			$maxBodyLength = ($maxColumnNameLength - strlen($namePostfix)
					- strlen($constraintNbr) - 2);
		} catch (EngineException $e) {
			echo $e;
			throw $e;
		}

		// Do any necessary trimming.
		if ($maxBodyLength !== -1 && strlen($name) > $maxBodyLength) {
			$name = substr($name, 0, $maxBodyLength);
		}

		$name .= self::STD_SEPARATOR_CHAR . $namePostfix
			. self::STD_SEPARATOR_CHAR . $constraintNbr;

		return $name;
	}
}
