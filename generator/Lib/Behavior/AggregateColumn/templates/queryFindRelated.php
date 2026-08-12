
/**
 * Finds the related <?php echo $foreignTable->getPhpName() ?> objects and keep them for later
 *
 * @param ?PropulsionPDO $con A connection object
 *
 * @return void
 */
protected function findRelated<?php echo $relationName ?>s($con)
{
	$criteria = clone $this;
	if ($this->useAliasInSQL) {
		// getModelAlias() is nullable and removeAlias() is not: aliasing being on
		// does not by itself prove one was set.
		$alias = $this->getModelAlias() ?? '';
		$criteria->removeAlias($alias);
	} else {
		$alias = '';
	}
	$this-><?php echo $variableName ?>s = <?php echo $foreignQueryName ?>::create()
		->join<?php echo $refRelationName ?>($alias)
		->mergeWith($criteria)
		->find($con);
}
