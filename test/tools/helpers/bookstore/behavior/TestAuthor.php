<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

// Author only exists once the bookstore fixtures are built (needs the shared
// Postgres testcontainer -- IntegrationDatabase::ensureReady()); this file is
// always required by bootstrap.php, so it needs its own guard rather than relying
// on bootstrap.php to skip requiring it (other fixture-independent helper files
// live alongside fixture-dependent ones in the same require list).
if (class_exists(Author::class)) {
	class TestAuthor extends Author {
		public function preInsert(?PropulsionPDO $con = null)
		{
			parent::preInsert($con);
			$this->setFirstName('PreInsertedFirstname');
			return true;
		}

		public function postInsert(?PropulsionPDO $con = null)
		{
			parent::postInsert($con);
			$this->setLastName('PostInsertedLastName');
		}

		public function preUpdate(?PropulsionPDO $con = null)
		{
			parent::preUpdate($con);
			$this->setFirstName('PreUpdatedFirstname');
			return true;
		}

		public function postUpdate(?PropulsionPDO $con = null)
		{
			parent::postUpdate($con);
			$this->setLastName('PostUpdatedLastName');
		}

		public function preSave(?PropulsionPDO $con = null)
		{
			parent::preSave($con);
			$this->setEmail("pre@save.com");
			return true;
		}

		public function postSave(?PropulsionPDO $con = null)
		{
			parent::postSave($con);
			$this->setAge(115);
		}

		public function preDelete(?PropulsionPDO $con = null)
		{
			parent::preDelete($con);
			$this->setFirstName("Pre-Deleted");
			return true;
		}

		public function postDelete(?PropulsionPDO $con = null)
		{
			parent::postDelete($con);
			$this->setLastName("Post-Deleted");
		}
	}

	class TestAuthorDeleteFalse extends TestAuthor
	{
		public function preDelete(?PropulsionPDO $con = null)
		{
			parent::preDelete($con);
			$this->setFirstName("Pre-Deleted");
			return false;
		}
	}
	class TestAuthorSaveFalse extends TestAuthor
	{
		public function preSave(?PropulsionPDO $con = null)
		{
			parent::preSave($con);
			$this->setEmail("pre@save.com");
			return false;
		}

	}
}