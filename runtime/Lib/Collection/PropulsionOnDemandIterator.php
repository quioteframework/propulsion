<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Collection;

use Propulsion\Formatter\PropulsionObjectFormatter;
use \Iterator;
use \PDOStatement;
use Propulsion\OM\BaseObject;
use Propulsion\Propulsion;
use Propulsion\Exception\PropulsionException;
use PDO;
/**
 * Class for iterating over a statement and returning one Propulsion object at a time
 *
 * @author     Francois Zaninotto
 */
class PropulsionOnDemandIterator implements Iterator
{
	/**
	 * @var       PropulsionObjectFormatter
	 */
	protected $formatter;

	/**
	 * @var       PDOStatement
	 */
	protected $stmt;

	protected
		$currentRow,
		$currentKey = -1,
		$isValid = null,
		$enableInstancePoolingOnFinish = false;

	/**
	 * @param     PropulsionObjectFormatter  $formatter
	 * @param     PDOStatement     $stmt
	 */
	public function __construct(PropulsionObjectFormatter $formatter, PDOStatement $stmt)
	{
		$this->formatter = $formatter;
		$this->stmt = $stmt;
		$this->enableInstancePoolingOnFinish = Propulsion::disableInstancePooling();
	}

	public function closeCursor()
	{
		$this->stmt->closeCursor();
	}

	/**
	 * Returns the number of rows in the resultset
	 * Warning: this number is inaccurate for most databases. Do not rely on it for a portable application.
	 *
	 * @return    integer  Number of results
	 */
	public function count()
	{
		return $this->stmt->rowCount();
	}

	// Iterator Interface

	/**
	 * Gets the current Model object in the collection
	 * This is where the hydration takes place.
	 *
	 * @see       PropulsionObjectFormatter::getAllObjectsFromRow()
	 *
	 * @return    BaseObject
	 */
	public function current(): mixed
	{
		return $this->formatter->getAllObjectsFromRow($this->currentRow);
	}

	/**
	 * Gets the current key in the iterator
	 *
	 * @return    string
	 */
	public function key(): mixed
	{
		return $this->currentKey;
	}

	/**
	 * Advances the curesor in the statement
	 * Closes the cursor if the end of the statement is reached
	 */
	public function next(): void
	{
		$this->currentRow = $this->stmt->fetch(PDO::FETCH_NUM);
		$this->currentKey++;
		$this->isValid = (bool) $this->currentRow;
		if (!$this->isValid) {
			$this->closeCursor();
			if ($this->enableInstancePoolingOnFinish) {
				Propulsion::enableInstancePooling();
			}
		}
	}

	/**
	 * Initializes the iterator by advancing to the first position
	 * This method can only be called once (this is a NoRewindIterator)
	 */
	public function rewind(): void
	{
		// check that the hydration can begin
		if (null === $this->formatter) {
			throw new PropulsionException('The On Demand collection requires a formatter. Add it by calling setFormatter()');
		}
		if (null === $this->stmt) {
			throw new PropulsionException('The On Demand collection requires a statement. Add it by calling setStatement()');
		}
		if (null !== $this->isValid) {
			throw new PropulsionException('The On Demand collection can only be iterated once');
		}

		// initialize the current row and key
		$this->next();
	}

	/**
	 * @return    boolean
	 */
	public function valid(): bool
	{
		return (bool) $this->isValid;
	}
}
