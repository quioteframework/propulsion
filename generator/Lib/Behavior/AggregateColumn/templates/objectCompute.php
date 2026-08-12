
/**
 * Computes the value of the aggregate column <?php echo $column->getName() ?>
 *
 * @param PropulsionPDO $con A connection object
 *
 * @return mixed The scalar result from the aggregate query
 */
public function compute<?php echo $column->getPhpName() ?>(PropulsionPDO $con)
{
	$stmt = $con->prepare('<?php echo $sql ?>');
	if ($stmt === false) {
		throw new PropulsionException('Could not prepare the aggregate query for <?php echo $column->getName() ?>');
	}
<?php foreach ($bindings as $key => $binding): ?>
  $stmt->bindValue(':p<?php echo $key ?>', $this->get<?php echo $binding ?>());
<?php endforeach; ?>
	$stmt->execute();
	$result = $stmt->fetchColumn();
	// FreeTDS/pdo_dblib (MSSQL) requires the cursor closed after a single
	// scalar fetch, or the next statement on this connection fails with
	// "results pending" -- harmless no-op on every other platform.
	$stmt->closeCursor();
	return $result;
}
