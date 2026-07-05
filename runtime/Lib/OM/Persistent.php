<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\OM;

/**
 * This interface defines methods related to saving an object
 *
 * @author     Hans Lellelid <hans@xmpl.org> (Propel)
 * @author     John D. McNally <jmcnally@collab.net> (Torque)
 * @author     Fedor K. <fedor@apache.org> (Torque)
 * @version    $Revision$
 */

use Propulsion\Connection\PropulsionPDO;
use Propulsion\Exception\PropulsionException;

interface Persistent
{

	/**
	 * getter for the object primaryKey.
	 *
	 * @return     mixed the object primaryKey: a scalar for a single-column key,
	 *             an array of scalars for a composite key, or null if unset.
	 */
	public function getPrimaryKey();

	/**
	 * Sets the PrimaryKey for the object.
	 *
	 * @param      mixed $primaryKey The new PrimaryKey object or string (result of PrimaryKey.toString()).
	 * @return     void
	 * @throws     \Exception This method might throw an exception
	 */
	public function setPrimaryKey(mixed $primaryKey);

	/**
	 * Returns whether the object has been modified, since it was
	 * last retrieved from storage.
	 *
	 * @return     boolean True if the object has been modified.
	 */
	public function isModified();

	/**
	 * Has specified column been modified?
	 *
	 * @param      string $col
	 * @return     boolean True if $col has been modified.
	 */
	public function isColumnModified($col);

	/**
	 * Returns whether the object has ever been saved.  This will
	 * be false, if the object was retrieved from storage or was created
	 * and then saved.
	 *
	 * @return     boolean True, if the object has never been persisted.
	 */
	public function isNew();

	/**
	 * Setter for the isNew attribute.  This method will be called
	 * by Propulsion-generated children and Peers.
	 *
	 * @param      boolean $b the state of the object.
	 */
	public function setNew($b);

	/**
	 * Resets (to false) the "modified" state for this object.
	 *
	 * @return     void
	 */
	public function resetModified();

	/**
	 * Whether this object has been deleted.
	 * @return     boolean The deleted state of this object.
	 */
	public function isDeleted();

	/**
	 * Specify whether this object has been deleted.
	 * @param      boolean $b The deleted state of this object.
	 * @return     void
	 */
	public function setDeleted($b);

	/**
	 * Deletes the object.
	 * @param      PropulsionPDO $con
	 * @return     void
	 * @throws     PropulsionException
	 */
	public function delete(?PropulsionPDO $con = null);

	/**
	 * Saves the object.
	 * @param      PropulsionPDO $con
	 * @return     void
	 * @throws     PropulsionException
	 */
	public function save(?PropulsionPDO $con = null);
}
