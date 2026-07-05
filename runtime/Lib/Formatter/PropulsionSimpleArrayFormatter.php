<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Formatter;

use PDOStatement;
use PDO;
use Propulsion\Exception\PropulsionException;
/**
 * Array formatter for Propulsion select query
 * format() returns a PropulsionArrayCollection of associative arrays, a string,
 * or an array
 *
 * @author     Benjamin Runnels
 * @version    $Revision$
 */
class PropulsionSimpleArrayFormatter extends PropulsionFormatter {
	protected $collectionName = 'Propulsion\\Collection\\PropulsionArrayCollection';

	public function format(PDOStatement $stmt) {
		$this->checkInit ();
		if ($class = $this->collectionName) {
			$collection = new $class();
			$collection->setModel ($this->class );
			$collection->setFormatter ($this);
		} else {
			$collection = array();
		}
		if ($this->isWithOneToMany () && $this->hasLimit) {
			throw new PropulsionException('Cannot use limit() in conjunction with with() on a one-to-many relationship. Please remove the with() call, or the limit() call.');
		}
		while ($row = $stmt->fetch (PDO::FETCH_NUM)) {
			if ($rowArray = $this->getStructuredArrayFromRow ($row)) {
				$collection[] = $rowArray;
			}
		}
		$stmt->closeCursor ();
		return $collection;
	}

	public function formatOne(PDOStatement $stmt) {
		$this->checkInit ();
		$result = null;
		while ($row = $stmt->fetch (PDO::FETCH_NUM)) {
			if ($rowArray = $this->getStructuredArrayFromRow ($row)) {
				$result = $rowArray;
			}
		}
		$stmt->closeCursor ();
		return $result;
	}

	public function isObjectFormatter() {
		return false;
	}

	public function getStructuredArrayFromRow($row) {
		$columnNames = array_keys($this->getAsColumns ());
		if (count($columnNames) > 1 && count($row) > 1) {
			$finalRow = array();
			foreach ($row as $index => $value) {
				$finalRow[str_replace('"', '', $columnNames[$index])] = $value;
			}
		} else {
			$finalRow = $row[0];
		}
		return $finalRow;
	}
}
