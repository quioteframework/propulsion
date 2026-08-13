/**
 * Updates the aggregate column <?php echo $column->getName() ?>
 *
 * @param ?PropulsionPDO $con A connection object; resolved from the datasource when null
 *
 * @return void
 */
public function update<?php echo $column->getPhpName() ?>(?PropulsionPDO $con = null)
{
	// Nullable, like every other generated method taking a connection. The
	// caller is the relation behavior's updateRelated*() loop, whose own $con is
	// nullable -- requiring one here only pushed the problem up to it.
	if ($con === null) {
		$con = Propulsion::getConnection(<?php echo $peerClassname ?>::DATABASE_NAME, Propulsion::CONNECTION_WRITE);
	}
	$this->set<?php echo $column->getPhpName() ?>($this->compute<?php echo $column->getPhpName() ?>($con));
	$this->save($con);
}
