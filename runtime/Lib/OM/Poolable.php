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
 * A model object that a generated Peer can keep in its instance pool.
 *
 * Exists because the pool spans two kinds of generated object that have no
 * other type in common. An ordinary model implements {@see Persistent}, but a
 * read-only one (a `<table readOnly="true">`, i.e. a database view) is emitted
 * without save()/delete()/isModified() at all, so it cannot -- and it is still
 * hydrated and still pooled. `BaseObject` is a class, not something a peer
 * signature should be pinned to.
 *
 * The type has to be shared rather than per-model because
 * `<Model>Peer::addInstanceToPool()` is *inherited*: a concrete-inheritance
 * table generates a real peer-class chain (ConcreteArticlePeer extends
 * ConcreteContentPeer), and a child peer re-declaring the method with its own
 * narrower model type would break contravariance. It also has to be a
 * supertype of the *base* generated class rather than the stub, because the
 * pooling call in save() is made from inside that base, where `$this` is not
 * provably the stub subclass.
 *
 * getPrimaryKey() is the one member: every generated object has it (BaseObject
 * declares it abstract), and it is what makes an object identifiable in a pool
 * at all. Deliberately left without a return type, matching
 * {@see Persistent::getPrimaryKey()} -- a single-column key returns that
 * column's scalar and a composite one returns an array, so implementations
 * narrow it differently.
 */
interface Poolable
{
	/**
	 * The value identifying this object, as its peer's pool keys it.
	 *
	 * @return mixed
	 */
	public function getPrimaryKey();
}
