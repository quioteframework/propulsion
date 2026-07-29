<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion;

/**
 * Explicit entity-state override for {@see UnitOfWork::attach()} -- lets a
 * caller tell the UnitOfWork how to treat an entity regardless of what its
 * own isNew()/isModified()/isDeleted() currently say, the way EF Core's
 * `Entry(entity).State = EntityState.Modified` does. Needed for a detached
 * entity (e.g. hydrated from a deserialized API request body rather than
 * loaded from the database) whose isNew() is otherwise meaningless -- a
 * fresh {@see \Propulsion\OM\BaseObject} always starts isNew() === true
 * regardless of whether it represents a brand new row or an existing one the
 * caller already knows the primary key of.
 */
enum EntityState
{
	/** Insert this entity on flush(), regardless of isNew(). */
	case Added;

	/** Update this entity on flush(), regardless of isNew()/isModified() --
	 * still requires at least one actually-modified column (see
	 * UnitOfWork::attach()'s own doc comment), since the UPDATE's SET clause
	 * comes from the object's own modifiedColumns either way. */
	case Modified;

	/** Delete this entity on flush(), regardless of isDeleted(). */
	case Deleted;

	/** Track this entity (e.g. for identity/dedup purposes) but don't do
	 * anything with it on flush() -- the explicit equivalent of an entity
	 * whose isNew()/isModified()/isDeleted() would all already say "leave
	 * this alone". */
	case Unchanged;
}
