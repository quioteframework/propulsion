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
use Propulsion\Collection\PropulsionCollection;
use Propulsion\Exception\PropulsionException;
use Propulsion\Util\StatementRows;
/**
 * Array formatter for Propulsion select query
 * format() returns a PropulsionArrayCollection of associative arrays, a string,
 * or an array
 *
 * @author     Benjamin Runnels
 * @version    $Revision$
 */
class PropulsionSimpleArrayFormatter extends PropulsionFormatter {
	/** @var class-string<PropulsionCollection> */
	protected string $collectionName = 'Propulsion\\Collection\\PropulsionArrayCollection';

	public function format(PDOStatement $stmt): mixed {
		$this->checkInit($stmt);

		return $this->formatRows(StatementRows::iterate($stmt));
	}

	/**
	 * @param     iterable<int, array<int, mixed>> $rows
	 */
	public function formatFromRows(iterable $rows): mixed {
		$this->checkInit();

		return $this->formatRows($rows);
	}

	public function supportsRowCaching(): bool {
		return true;
	}

	/**
	 * The single per-row body behind both {@see format()} and
	 * {@see formatFromRows()}.
	 *
	 * @param     iterable<int, array<int, mixed>> $rows
	 */
	protected function formatRows(iterable $rows): mixed {
		if ($class = $this->collectionName) {
			$collectionObj = new $class();
			$collectionObj->setModel($this->requireClass());
			$collectionObj->setFormatter($this);
			$collection = $collectionObj;
		} else {
			$collection = array();
		}
		if ($this->isWithOneToMany () && $this->hasLimit) {
			throw new PropulsionException('Cannot use limit() in conjunction with with() on a one-to-many relationship. Please remove the with() call, or the limit() call.');
		}
		foreach ($rows as $row) {
			if ($rowArray = $this->getStructuredArrayFromRow ($row)) {
				$collection[] = $rowArray;
			}
		}
		return $collection;
	}

	public function formatOne(PDOStatement $stmt): mixed {
		$this->checkInit($stmt);

		return $this->formatOneRow(StatementRows::iterate($stmt));
	}

	/**
	 * @param     iterable<int, array<int, mixed>> $rows
	 */
	public function formatOneFromRows(iterable $rows): mixed {
		$this->checkInit();

		return $this->formatOneRow($rows);
	}

	/**
	 * @param     iterable<int, array<int, mixed>> $rows
	 */
	protected function formatOneRow(iterable $rows): mixed {
		$result = null;
		foreach ($rows as $row) {
			if ($rowArray = $this->getStructuredArrayFromRow ($row)) {
				$result = $rowArray;
			}
		}
		return $result;
	}

	public function isObjectFormatter(): bool {
		return false;
	}

	/**
	 * @param array<int, mixed> $row
	 * @return mixed
	 */
	public function getStructuredArrayFromRow(array $row): mixed {
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
